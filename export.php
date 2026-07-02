#!/usr/bin/env php
<?php
/**
 * MailerLite export tool.
 *
 * Usage:
 *   php export.php users [options]
 *   php export.php segment "Segment Name" [options]
 *
 * Commands:
 *   users              Export email, names, and all custom fields for every subscriber.
 *   segment <name>     Export all email addresses belonging to the given segment
 *                      (matched by name).
 *
 * Options:
 *   --token=YOUR_API_KEY     MailerLite API token. Falls back to the
 *                            MAILERLITE_API_KEY env var, then to a local
 *                            api_token.txt file, in that order.
 *   --output=path/to.csv     Output file (default depends on command).
 *   --limit=1000             Page size per API request. The subscribers
 *                            endpoint allows up to 1000; the segment
 *                            subscribers endpoint is capped at 50 by
 *                            MailerLite regardless of what's requested.
 *   --status=active          ("users" command only) Only export subscribers with
 *                            this status (active, unsubscribed, unconfirmed,
 *                            bounced, junk)
 *
 * Requires: composer require mailerlite/mailerlite-php
 *
 * Note on implementation: the "users" command uses the mailerlite/mailerlite-php
 * SDK (subscribers->get()), which correctly forwards pagination params. The
 * "segment" command talks to MailerLite's REST API directly, because the
 * SDK's segments->getSubscribers() does not reliably forward the limit/cursor
 * parameters, which previously caused an infinite loop re-fetching page 1.
 * Both paths hit the same official MailerLite Connect API.
 */

require __DIR__ . '/vendor/autoload.php';

use MailerLite\MailerLite;

// ---------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------

$argvCopy = $argv;
array_shift($argvCopy); // script name

$command = null;
$positional = [];
$options = [];

foreach ($argvCopy as $arg) {
    if (strpos($arg, '--') === 0) {
        $pair = substr($arg, 2);
        if (strpos($pair, '=') !== false) {
            [$key, $value] = explode('=', $pair, 2);
        } else {
            $key = $pair;
            $value = true;
        }
        $options[$key] = $value;
    } elseif ($command === null) {
        $command = $arg;
    } else {
        $positional[] = $arg;
    }
}

$usage = "Usage:\n"
    . "  php export.php users [--token=KEY] [--output=file.csv] [--limit=1000] [--status=active]\n"
    . "  php export.php segment \"Segment Name\" [--token=KEY] [--output=file.csv] [--limit=50]\n";

if (!in_array($command, ['users', 'segment'], true)) {
    fwrite(STDERR, $usage);
    exit(1);
}

if ($command === 'segment' && empty($positional)) {
    fwrite(STDERR, "Error: 'segment' requires a segment name.\n\n" . $usage);
    exit(1);
}

$segmentName = $command === 'segment' ? $positional[0] : null;

// ---------------------------------------------------------------------
// Resolve API token
// ---------------------------------------------------------------------

$apiKey = $options['token'] ?? getenv('MAILERLITE_API_KEY') ?: null;

if (!$apiKey && is_readable('api_token.txt')) {
    $apiKey = trim((string) file_get_contents('api_token.txt'));
    if ($apiKey === '') {
        $apiKey = null;
    }
}

if (!$apiKey) {
    fwrite(STDERR, "Error: no API token provided.\n");
    fwrite(STDERR, "Pass --token=YOUR_KEY, set the MAILERLITE_API_KEY environment variable,\n");
    fwrite(STDERR, "or put the token in a local api_token.txt file.\n");
    exit(1);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 1000;
if ($limit < 1 || $limit > 1000) {
    $limit = 1000;
}

$statusFilter = $options['status'] ?? null;

// Safety net: if a fetch loop ever runs this many pages without finishing,
// abort loudly instead of hanging forever. 100k records at even the
// smallest page size is far beyond any reasonable list/segment size.
const MAX_PAGES = 5000;

// ---------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------

/**
 * Normalizes whatever shape the SDK returns into a plain array,
 * regardless of whether it's already an array, a stdClass, or an
 * object exposing getBody()/toArray().
 */
function normalizeResponse($response): array
{
    if (is_array($response)) {
        if (isset($response['body']) && is_array($response['body'])) {
            return $response['body'];
        }
        return $response;
    }

    if (is_object($response)) {
        if (method_exists($response, 'toArray')) {
            return $response->toArray();
        }
        if (method_exists($response, 'getBody')) {
            $body = (string) $response->getBody();
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        $decoded = json_decode(json_encode($response), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function writeCsvLine(array $row): string
{
    $escaped = array_map(function ($value) {
        $value = $value === null ? '' : (string) $value;
        $value = str_replace('"', '""', $value);
        return '"' . $value . '"';
    }, $row);

    return implode("\t", $escaped) . "\n";
}

/**
 * Direct call to the MailerLite Connect REST API. Used where the SDK's
 * own wrapper has proven unreliable for pagination (see segments).
 */
function mailerLiteHttpGet(string $path, string $apiKey, array $query = []): array
{
    $url = 'https://connect.mailerlite.com/api/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException("cURL error requesting {$url}: {$error}");
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);

    if ($status >= 400) {
        $message = is_array($decoded) && isset($decoded['message']) ? $decoded['message'] : $body;
        throw new \RuntimeException("MailerLite API returned HTTP {$status} for {$url}: {$message}");
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Extracts the cursor for the next page from a cursor-paginated
 * response (subscribers, segments/{id}/subscribers, ...).
 */
function nextCursorFrom(array $data): ?string
{
    $nextCursor = $data['meta']['next_cursor'] ?? null;

    if (!$nextCursor && !empty($data['links']['next'])) {
        $query = parse_url($data['links']['next'], PHP_URL_QUERY);
        if ($query) {
            parse_str($query, $parsedQuery);
            $nextCursor = $parsedQuery['cursor'] ?? null;
        }
    }

    return $nextCursor ?: null;
}

/**
 * Runs $fetchPage($params) repeatedly, following cursor pagination,
 * and returns every record across every page. Aborts if the cursor
 * ever fails to advance (repeats or the page count runs away), so a
 * misbehaving pagination response can't cause an infinite loop.
 */
function fetchAllCursorPaginated(callable $fetchPage, array $baseParams, int $limit): array
{
    $all = [];
    $cursor = null;
    $page = 0;

    do {
        $params = array_merge($baseParams, ['limit' => $limit]);
        if ($cursor !== null) {
            $params['cursor'] = $cursor;
        }

        $response = $fetchPage($params);
        $data = normalizeResponse($response);

        $records = $data['data'] ?? [];
        if (!is_array($records)) {
            $records = [];
        }

        foreach ($records as $record) {
            $all[] = $record;
        }

        $page++;
        echo "  page {$page}: " . count($records) . " records (total so far: " . count($all) . ")\n";

        $nextCursor = nextCursorFrom($data);

        if ($nextCursor !== null && $nextCursor === $cursor) {
            throw new \RuntimeException(
                "Pagination did not advance (same cursor returned twice) after {$page} pages. " .
                "Stopping to avoid an infinite loop. Fetched " . count($all) . " records so far."
            );
        }

        if ($page > MAX_PAGES) {
            throw new \RuntimeException(
                "Exceeded " . MAX_PAGES . " pages without finishing pagination. " .
                "Stopping to avoid an infinite loop. Fetched " . count($all) . " records so far."
            );
        }

        $cursor = $nextCursor;

        if (empty($records)) {
            break;
        }
    } while (!empty($cursor));

    return $all;
}

// ---------------------------------------------------------------------
// Command: users
// ---------------------------------------------------------------------

if ($command === 'users') {
    $mailerLite = new MailerLite(['api_key' => $apiKey]);
    $outputPath = $options['output'] ?? ('subscribers_export_' . date('Y-m-d_His') . '.csv');

    echo "Fetching subscribers from MailerLite...\n";

    $baseParams = [];
    if ($statusFilter !== null) {
        $baseParams['filter'] = ['status' => $statusFilter];
    }

    try {
        $allSubscribers = fetchAllCursorPaginated(
            fn ($params) => $mailerLite->subscribers->get($params),
            $baseParams,
            $limit
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "Failed fetching subscribers: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "Done. Total subscribers fetched: " . count($allSubscribers) . "\n";

    $fieldKeys = [];
    foreach ($allSubscribers as $subscriber) {
        $fields = $subscriber['fields'] ?? [];
        if (is_array($fields)) {
            foreach (array_keys($fields) as $fieldName) {
                $fieldKeys[$fieldName] = true;
            }
        }
    }
    $fieldNames = array_keys($fieldKeys);
    sort($fieldNames);

    $header = array_merge(
        ['id', 'email', 'status', 'subscribed_at', 'unsubscribed_at', 'created_at', 'updated_at'],
        $fieldNames
    );

    $fh = fopen($outputPath, 'w');
    if ($fh === false) {
        fwrite(STDERR, "Could not open {$outputPath} for writing.\n");
        exit(1);
    }

    fwrite($fh, writeCsvLine($header));

    foreach ($allSubscribers as $subscriber) {
        $fields = $subscriber['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $row = [
            $subscriber['id'] ?? '',
            $subscriber['email'] ?? '',
            $subscriber['status'] ?? '',
            $subscriber['subscribed_at'] ?? '',
            $subscriber['unsubscribed_at'] ?? '',
            $subscriber['created_at'] ?? '',
            $subscriber['updated_at'] ?? '',
        ];

        foreach ($fieldNames as $fieldName) {
            $value = $fields[$fieldName] ?? '';
            $row[] = is_array($value) ? json_encode($value) : $value;
        }

        fwrite($fh, writeCsvLine($row));
    }

    fclose($fh);

    echo "Export written to: {$outputPath}\n";
    exit(0);
}

// ---------------------------------------------------------------------
// Command: segment (talks to the REST API directly, see note at top)
// ---------------------------------------------------------------------

if ($command === 'segment') {
    echo "Looking up segment \"{$segmentName}\"...\n";

    $segments = [];
    $page = 1;

    try {
        do {
            $data = mailerLiteHttpGet('segments', $apiKey, ['page' => $page, 'limit' => 50]);

            $records = $data['data'] ?? [];
            if (!is_array($records)) {
                $records = [];
            }
            foreach ($records as $record) {
                $segments[] = $record;
            }

            $currentPage = $data['meta']['current_page'] ?? $page;
            $lastPage = $data['meta']['last_page'] ?? $page;
            $page = $currentPage + 1;

            if ($page > MAX_PAGES) {
                throw new \RuntimeException("Exceeded " . MAX_PAGES . " pages while listing segments.");
            }
        } while ($currentPage < $lastPage);
    } catch (\Throwable $e) {
        fwrite(STDERR, "API request failed while listing segments: " . $e->getMessage() . "\n");
        exit(1);
    }

    $matches = array_values(array_filter($segments, function ($segment) use ($segmentName) {
        return isset($segment['name']) && strcasecmp((string) $segment['name'], $segmentName) === 0;
    }));

    if (empty($matches)) {
        fwrite(STDERR, "Error: no segment named \"{$segmentName}\" was found.\n");
        if (!empty($segments)) {
            fwrite(STDERR, "Available segments:\n");
            foreach ($segments as $segment) {
                fwrite(STDERR, "  - " . ($segment['name'] ?? '(unnamed)') . "\n");
            }
        }
        exit(1);
    }

    $segment = $matches[0];
    $segmentId = $segment['id'];
    $expectedTotal = $segment['total'] ?? null;

    echo "Found segment \"{$segment['name']}\" (id {$segmentId}"
        . ($expectedTotal !== null ? ", {$expectedTotal} subscribers" : "")
        . "). Fetching subscribers...\n";

    // The segment subscribers endpoint caps at 50 per page regardless of
    // what's requested, so we just ask for 50 and follow the cursor.
    try {
        $subscribers = fetchAllCursorPaginated(
            fn ($params) => mailerLiteHttpGet("segments/{$segmentId}/subscribers", $apiKey, $params),
            [],
            50
        );
    } catch (\Throwable $e) {
        fwrite(STDERR, "Failed fetching segment subscribers: " . $e->getMessage() . "\n");
        exit(1);
    }

    echo "Done. Total subscribers in segment: " . count($subscribers) . "\n";

    if ($expectedTotal !== null && count($subscribers) != $expectedTotal) {
        fwrite(STDERR, "Warning: segment reports {$expectedTotal} subscribers but "
            . count($subscribers) . " were fetched. The export may be incomplete.\n");
    }

    $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $segmentName);
    $outputPath = $options['output'] ?? ("segment_{$safeName}_emails_" . date('Y-m-d_His') . '.csv');

    $fh = fopen($outputPath, 'w');
    if ($fh === false) {
        fwrite(STDERR, "Could not open {$outputPath} for writing.\n");
        exit(1);
    }

    fwrite($fh, writeCsvLine(['email']));

    foreach ($subscribers as $subscriber) {
        fwrite($fh, writeCsvLine([$subscriber['email'] ?? '']));
    }

    fclose($fh);

    echo "Export written to: {$outputPath}\n";
    exit(0);
}
