# tribunedesk/cms-protocol

The rules of the [Tribune Desk CMS API](https://github.com/Tribunedesk/tribunedesk-cms-api) for
PHP: checking the delivery webhook's signature, building the answer to a delivery, the link back
after publishing, resolving placeholder links, the media library sync. Plain PHP 8.3+, no runtime
dependencies, **no HTTP transport**: your CMS already has an HTTP client (Guzzle in Drupal, `Http`
in Laravel, `wp_remote_*` in WordPress), and the library only builds request bodies and reads
answers.

The [Tribune Desk WordPress plugin](https://github.com/Tribunedesk/tribunedesk-wordpress) runs on
these classes: it keeps a copy of `src/` from a tag of this repository, and its CI checks the
copy byte for byte.

## Installation

```bash
composer require tribunedesk/cms-protocol:^1.0
```

Until the package is on Packagist, point Composer at this repository first:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/Tribunedesk/tribunedesk-cms-php"}
    ],
    "require": {
        "tribunedesk/cms-protocol": "^1.0"
    }
}
```

## Classes

All classes are in `TribuneDesk\Protocol` and have only static methods. The level is the
integration level of the contract; the guide link is the chapter that explains the rule.

| Class | What it does | Level | Guide |
|---|---|---|---|
| `WebhookSignature` | Verifies the Standard Webhooks signature of a delivery (two signatures while the secret rotates, 300 s tolerance) | 1 | [Verifying the signature](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/1-article-delivery.md#verifying-the-signature) |
| `DraftRules` | Create, update or skip a delivery for a post already in the CMS; title and excerpt rules | 1 | [Article delivery webhook](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/1-article-delivery.md#article-delivery-webhook) |
| `DeliveryAnswer` | Builds the answer to a delivery (`cms_post_id`, `edit_url`, `permalink`, `featured_image`) | 1 (`permalink`: 2, `featured_image`: 4) | [Fields of your answer](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/1-article-delivery.md#fields-of-your-answer) |
| `ImageHostRule` | Which image hosts the CMS may download from (`image_hosts` of the status or the settings; without a list, nothing) | 1 | [Where images come from](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/1-article-delivery.md#where-images-come-from) |
| `LinkBack` | Builds the body of `POST /v3/cms/articles/published` and reads its results | 2 | [Link back](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/2-link-back.md) |
| `AuthorOrder` | The order in which the delivery's people are matched to CMS users | 3 | [SEO, author and language](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/3-full-content.md#seo-author-and-language) |
| `BacklinkSources` | Reads `backlink_sources` and `evergreen` for the "read more" box | 3 | [The "read more" box](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/3-full-content.md#the-read-more-box) |
| `PlaceholderLinks` | Swaps placeholder links for the CMS's own addresses, unwraps the missing ones | 3 | [Internal links](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/3-full-content.md#internal-links) |
| `ChangesFeed` | Field versions and conflict rules of `GET /v3/cms/changes` and `POST /v3/cms/media/fields` | 4 | [The sync cycle](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/4-media-library.md#the-sync-cycle) |
| `Resync` | Pages of `POST /v3/cms/resync` and the check of its answer | 4 | [Cursors and expiry](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/guide/4-media-library.md#cursors-and-expiry) |
| `ApiErrors` | Maps an error answer of any `/v3/cms` route to its code and `retry_after` | all | [Errors](https://github.com/Tribunedesk/tribunedesk-cms-api/blob/v3.4.1-draft/README.md#errors) |
| `ContractVersion` | `ContractVersion::TESTED`: the contract version this release is tested against | — | — |

## Example: a delivery receiver in plain PHP

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use TribuneDesk\Protocol\DeliveryAnswer;
use TribuneDesk\Protocol\WebhookSignature;

$secret = getenv('TRIBUNEDESK_WEBHOOK_SECRET'); // whsec_…, from the Tribune Desk settings
$body   = file_get_contents('php://input');     // the raw body: verify before parsing JSON
$headers = function_exists('getallheaders') ? getallheaders() : [];

header('Content-Type: application/json');
if (!WebhookSignature::verify($secret, $headers, $body)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_signature']);
    exit;
}

$article = json_decode($body, true);
try {
    $id = save_as_draft($article); // your CMS: int or text id (a UUID works)
    echo json_encode(DeliveryAnswer::build($id, 'created', "https://cms.example.com/edit/$id", null, null));
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    echo json_encode(DeliveryAnswer::internal_error());
}
```

`DeliveryAnswer::build` throws `InvalidArgumentException` for an answer the contract would not
take: a `featured_image` without a media id (neither `cms_media_id` nor a positive
`attachment_id`), or an id that is not UTF-8 or is too long (`cms_post_id` over 128 characters,
`cms_media_id` over 255). In the example above that ends as a `500` and Tribune Desk sends the
article again, so pass a featured image only when you have its id, or build the answer before
you save anything.

`WebhookSignature::verify` takes the headers in any of the usual shapes: plain
(`webhook-id => '…'`), PSR-7 or Symfony (`Webhook-Id => ['…']`) and WordPress
(`webhook_id => ['…']`).

## Versioning

The library follows semantic versioning on its own. `ContractVersion::TESTED` says which version
of the CMS API contract a release is tested against; the tests run the signature vectors
published with that contract tag (`tests/fixtures/contract/`), and CI checks the copy against
the tag.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
