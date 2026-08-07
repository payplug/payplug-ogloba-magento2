# Changelog - Payplug Ogloba Module

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

### Added

- Ogloba gift card payment method (`payplug_payments_ogloba`) on the Magento gateway architecture.
- Admin configuration: method settings, plus the Ogloba environment, hosts, ids and HMAC key.
- Luma checkout renderer, the customer being redirected to the Ogloba payment page.
- Notification and customer return share a single `Service\ResolvePayment`: one verdict, one path.
- HMAC verification of the return payload, plus a correlation check against the order references.
- A proven return resolves the order itself instead of always redirecting to the success page.
- An unproven return never touches the order: it only routes the customer and restores the quote.
- Mandatory lock shared by both channels, so one operation is never resolved twice at a time.
- Non-final statuses (`Created`, `Payment_In_Progress`, `Authorizing`) leave the order open.
- `Authorized` is out of scope: the order awaits a final status, with a comment and an error log.
- An unknown status is handled as a failed transaction and reported as unrecognised.
- An order left in `new` is treated as awaiting its verdict instead of being stranded.
- A `Charged` landing on a cancelled order is written to the history and logged for manual review.
- Order history comments name the Ogloba status and spell out what it means.
- Every return notes in the history that the customer came back from the payment page.
- The cart message states a resolved transaction plainly, cautious only when nothing is proven.
- A `Charged` order is queued for invoicing on `payplug.ogloba.order.invoicing`, invoiced offline.
- The invoicing consumer records a Magento capture transaction keyed on the Ogloba operation.
- The order confirmation email is held back until the payment is charged, then sent once.
- The payment records the resolving channel (`resolved_by`) and the Ogloba order id.
- The payment records the Ogloba environment (`environment`) it was sent to.
- Admin order view: Payment Information block with the Ogloba references, adminhtml area only.
- Debug Mode drops the payload records unless it is on; the events stay at `info` either way.
- Full logging of the return request (method, URI, query, post, route params, body, referer).
- Shared collaborators live under `Service\`, one class per action exposing a single `execute()`.
- The channel and the outcome are interface constants in `Api\Data\`, not enums.
- en_US, fr_FR, es_ES and it_IT translations.
