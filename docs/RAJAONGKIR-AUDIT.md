# RajaOngkir End-to-End Audit

## Scope and verified configuration

- Target agent: `siswoyo@primeclassy.com` (`agent_id = 2`).
- Origin selected from the official Komerce V2 destination search: ID `4816`, label `-, BANDUNG, BANDUNG, JAWA BARAT, 40614`.
- Adapter base URL: `https://rajaongkir.komerce.id/api/v1`.
- Credential storage: encrypted `agent_shipping_provider_configs.config`, scoped by agent and never returned by an API response.

## Implemented flow

1. An agent enters an API key and searches for an official origin from Dashboard → Ekspedisi Saya.
2. The browser sends the key in a protected POST body. The backend calls `destination/domestic-destination` with the `key` header and returns sanitized destinations.
3. Saving revalidates the selected ID against RajaOngkir and stores the canonical label. Client-supplied origin labels are not trusted.
4. Checkout derives product/variation weight on the server, multiplies it by quantity, and rejects weights below one gram.
5. The buyer's village hierarchy is resolved dynamically to a Komerce V2 destination and cached. The legacy regency-level `rajaongkir_city_id` mapping is no longer used for quotes.
6. The backend posts `origin`, `destination`, total `weight` in grams, and colon-separated courier codes to `calculate/domestic-cost`.
7. Checkout shows the returned courier/service options in ascending price order. The browser does not provide a trusted shipping price.
8. Order creation calls RajaOngkir again and verifies the selected courier/service. An unavailable selection returns HTTP 422 and creates no order.
9. A successful order stores cost, provider, courier, service, description, ETD, origin/destination IDs and labels, and weight in `shipments.provider_meta`.

## Failure and security behavior

- Provider connection failures, invalid responses, missing destination matches, and empty services produce safe client messages.
- Structured logs contain provider, agent, route IDs, weight, courier codes, status, HTTP status, and duration. They exclude credentials and full provider payloads.
- Rate results are cached for five minutes; destination mappings are cached for thirty days.
- If a buyer explicitly chooses RajaOngkir or a courier/service, failure rejects the order instead of silently selecting another service or free shipping.
- A prior legacy diagnostic emitted the decrypted API key inside a local connection-exception trace. Rotate that key before production use. The legacy sync command and adapter were removed.

## Verification

- Official V2 destination lookup for Kota Bandung returned HTTP 200 and canonical ID `4816` using the existing encrypted credential.
- Feature tests cover request parameters, weight units, destination resolution, sorted services, selected-service verification, immutable shipment metadata, credential isolation, safe failure, and provider precedence.
