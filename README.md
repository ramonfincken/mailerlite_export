# MailerLite Subscriber Export

Exports every subscriber's email, name(s), and all custom fields from
MailerLite to a CSV file. Handles pagination automatically, so it will
fetch your entire list no matter how many subscribers you have.

## Setup

1. Install dependencies (requires PHP 7.4+ and Composer):

   ```
   composer install
   ```

2. Get your API token from MailerLite: **Integrations → API** in your
   MailerLite account.

## Usage

### Export all subscribers

```
php export.php users --token=YOUR_API_KEY
```

### Export the email addresses of a single segment

```
php export.php segment "VIP Customers" --token=YOUR_API_KEY
```

The segment name is matched case-insensitively. If no segment matches,
the script lists the available segment names so you can copy the
exact spelling.

### Providing the token

The token is resolved in this order:

1. `--token=YOUR_API_KEY` on the command line
2. `MAILERLITE_API_KEY` environment variable
3. A local `api_token.txt` file (just the raw token, no quotes) in the
   directory you run the script from

```
export MAILERLITE_API_KEY=your_key_here
php export.php users
```

```
echo "your_key_here" > api_token.txt
php export.php users
```

### Options

| Option       | Description                                                          | Default                                       |
|--------------|-----------------------------------------------------------------------|-------------------------------------------------|
| `--token`    | MailerLite API token                                                  | `MAILERLITE_API_KEY` env var / `api_token.txt`   |
| `--output`   | Output CSV file path                                                  | `subscribers_export_<timestamp>.csv` (users) / `segment_<name>_emails_<timestamp>.csv` (segment) |
| `--limit`    | Records fetched per API request (`users` only, max 1000). The segment subscribers endpoint is capped at 50 by MailerLite itself, so `segment` always uses 50. | `1000` |
| `--status`   | (`users` only) Only export subscribers with this status (`active`, `unsubscribed`, `unconfirmed`, `bounced`, `junk`) | all statuses |

### Implementation note

`users` uses the `mailerlite/mailerlite-php` SDK's `subscribers->get()`,
which forwards pagination params correctly. `segment` talks to the
MailerLite REST API directly (same endpoints, same token) because the
SDK's `segments->getSubscribers()` doesn't reliably forward the
`limit`/`cursor` params, which previously caused it to re-fetch the
same first page forever. As a safety net, both pagination loops now
abort immediately with a clear error if a cursor is ever returned
twice in a row, instead of looping indefinitely.

Examples:

```
php export.php users --token=abc123 --output=all_subscribers.csv --status=active
php export.php segment "Newsletter" --token=abc123 --output=newsletter_emails.csv
```

## Output format

The CSV is **tab-delimited**, and every cell is wrapped in double
quotes (embedded quotes are escaped by doubling them, e.g. `"` becomes
`""`), for example:

```
"id"	"email"	"status"	"subscribed_at"	"unsubscribed_at"	"created_at"	"updated_at"	"company"	"country"	"last_name"	"name"
"1"	"jane@example.com"	"active"	"2024-01-01 10:00:00"	""	"2024-01-01 10:00:00"	"2024-01-01 10:00:00"	"Acme Inc"	"US"	"Doe"	"Jane"
```

Columns:

- `id`, `email`, `status`, `subscribed_at`, `unsubscribed_at`, `created_at`, `updated_at`
- Every custom field found across all subscribers (including built-in
  ones MailerLite stores as fields, like `name` and `last_name`),
  added as columns in alphabetical order. If a given subscriber
  doesn't have a value for a field, the cell is left empty (`""`).

## Notes

- The script buffers all subscribers in memory before writing the
  CSV, so it can build one header row covering every field key found
  across the whole list. This is fine for lists up to several hundred
  thousand subscribers; for extremely large lists you may want to
  switch to a streaming two-pass approach.
- If MailerLite changes their SDK's response shape in a future
  release, the `normalizeResponse()` function is the place to adjust.
