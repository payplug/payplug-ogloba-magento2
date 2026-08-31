# Changelog - Payplug Ogloba Module

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

### Added

- Ogloba gift card payment method (`payplug_payments_ogloba`) on the Magento gateway architecture.
- Online total and partial refunds from the back office credit memo, through the Ogloba `/refunds` call.
- The refund request is signed with the HMAC key and carries the Limonetik order id stored on the payment.
- Each refund gets its own Limonetik operation id, recorded as the Magento refund transaction id.
- The refund sends the amount Magento books, and is refused when order and base currency differ.
- `Refunded` completes the credit memo at once; `Refunding` completes it and awaits the notification.
- Any other refund status aborts the credit memo, with an explicit back office error and an error log.
- A refund notification landing on a resolved order updates the refund status and the order history.
- The refund is refused when the order was paid on another Ogloba environment than the configured one.
- Admin order view: the payment block shows the refund status and the last refund operation id.
- A refund covering only part of what was paid reads as partially refunded.
- Admin configuration: method settings, plus the Ogloba environment, hosts, ids and HMAC key.
- Ogloba calls give up after 5 s without a connection, 10 s in the checkout and 30 s for a refund.
- Luma checkout renderer, the customer being redirected to the Ogloba payment page.
- Notification and customer return share a single `Service\ResolvePayment`: one verdict, one path.
- The Ogloba operation is the payment transaction id, so both channels find the order on an indexed match.
- HMAC verification of the return payload, plus a correlation check against the order references.
- A proven return resolves the order itself instead of always redirecting to the success page.
- An unproven return never touches the order and never restores the cart: it only routes the customer.
- Mandatory lock shared by both channels, so one operation is never resolved twice at a time.
- Non-final statuses (`Created`, `Payment_In_Progress`, `Authorizing`) leave the order open.
- `Authorized` is out of scope: the order awaits a final status, with a comment and an error log.
- An unknown status is handled as a failed transaction and reported as unrecognised.
- An order left in `new` is treated as awaiting its verdict instead of being stranded.
- A `Charged` landing on a cancelled order is written to the history and logged for manual review.
- Order history comments name the Ogloba status and spell out what it means.
- Every return notes in the history that the customer came back from the payment page.
- That comment is written on its own, so a notification resolving the order at the same time is kept.
- A proven failure restores the cart and states plainly that nothing was charged.
- An unproven return lands on the order confirmation, with a notice that the payment is being confirmed.
- A `Charged` order is queued for invoicing on `payplug.ogloba.order.invoicing`, invoiced offline.
- The invoicing consumer records a Magento capture transaction keyed on the Ogloba operation.
- An invoicing failure is reported to the queue instead of being acknowledged, never silently lost.
- A notification landing on a paid but uninvoiced order queues the invoicing again.
- The order confirmation email is held back until the payment is charged, then sent once.
- The payment records the resolving channel (`resolved_by`) and the Ogloba order id.
- The payment records the Ogloba environment (`environment`) it was sent to.
- Admin order view: Payment Information block with the Ogloba references, adminhtml area only.
- Debug Mode drops the payload records unless it is on; the events stay at `info` either way.
- Sales > Ogloba API Logs reads the end of the log from the back office, most recent line first.
- The log page reads the last 2 MB at most, keeps 100 to 2000 lines of it and filters them on a string.
- The log page has its own ACL resource and says when Debug Mode is off on every store view.
- Full logging of the return request (method, URI, query, post, route params, body, referer).
- The signature verdict is logged on both channels, `info` when verified and `error` when not.
- Verification fails closed when the scope has no usable HMAC key, outgoing requests included.
- A signature mismatch logs the re-encoded payload, to be compared with the one received.
- An invalid signature on the return is logged with the source IP and the payload received.
- Shared collaborators live under `Service\`, one class per action exposing a single `execute()`.
- The channel and the outcome are interface constants in `Api\Data\`, not enums.
- en_US, fr_FR, es_ES and it_IT translations.
