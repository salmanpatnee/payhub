# Beyond Bancard research

Researched 2026-11-04. Sources: beyondbancard.com pages (home, `/operate/payment-gateways/`, `/accept/online-payments/`, `/multi-bins/`, `/who-handles-payment-processing/`), web search, Shark Processing overview, BBB, Trustpilot, and the Beyond Pay WooCommerce plugin README. Context7 has no Beyond Bancard docs (its only "Beyond" entry is the unrelated Beyond Pricing API).

## Bottom line

Beyond Bancard is a merchant account reseller (ISO/MSP), not a gateway with its own API. There is nothing to plug PayHub or WooCommerce into until Beyond Bancard tells us which gateway we get.

## Confirmed on their own site

- Registered ISO/MSP of Esquire Bank (Jericho, NY), Merrick Bank (South Jordan, UT) and Mission Valley Bank (Sun Valley, CA). Also says it operates as an ISO of Fiserv Canada Ltd. Address: 500 N State College Blvd, Suite 800, Orange, CA.
- Serves conventional and high-risk merchants. Products grouped as Accept, Protect, Grow, Operate.
- No public API docs, no plugin list, no WooCommerce mention anywhere.
- Gateways are listed as a service ("set up as part of your merchant account") but **no gateway is named**.
- Three connection routes are described: a plug-in or cart setting where compatible, a hosted payment page or link, or custom code using the gateway's own docs. They ask for your platform so they can check compatibility.
- Gateway tokens can be saved for later payments if the setup is hosted or tokenized.
- Authorize.net, Fiserv and TSYS appear on the home page as partners.

## Not confirmed by Beyond Bancard

- Shark Processing (third party) says they offer Authorize.Net, USAePay, PayTrace and NMI. Their own pages do not say this. Do not present "it is Authorize.net" as fact.

## Beyond Pay is a different company

- Beyond Pay (getbeyond.com) is a separate gateway. No source links it to Beyond Bancard.
- Its WooCommerce plugin README says it is **discontinued and should not be used for new installations**.
- `developer.getbeyond.com` did not load, so its docs were not read.

## Complaints

- One unverified BBB review says accounts are terminated after a reserve hold is met. BBB responses point to merchant-side setup problems.
- Trustpilot is mixed to positive. One review complains about an unusable POS system and cancellation fees.
- No lawsuit or regulator data found. Pricing is not public.

## What it means for PayHub

| If the gateway is | Effort |
|---|---|
| Authorize.net | Low. Already supported (spec 0007). Add an account, set AVS response B to Allow, test. |
| USAePay, PayTrace or NMI | High. A ninth provider across pay page, webhooks, account CRUD, CSV, dashboard and receipts. Needs docs first. |
| Anything with only a hosted page | Medium. Redirect flow like Whop's redirect mode, status from a signed webhook or a lookup. |

WooCommerce: a gateway plugin would charge outside PayHub. To keep payments in PayHub, WooCommerce would need to send the customer to a PayHub pay link.

## Questions for Beyond Bancard

1. Which gateway will we be given?
2. Is there a sandbox and API documentation?
3. Is there a WooCommerce plugin, or only a hosted page?
4. Which webhooks or status lookups exist? PayHub needs them to confirm payments.
5. Is 3D Secure supported? Which currencies (USD, GBP)? Any high-risk restrictions?
6. Can we use our own card fields (PCI level)? What are the reserve, hold and termination terms?

Contact: (844) 365-3050, sales@beyondbancard.com.
