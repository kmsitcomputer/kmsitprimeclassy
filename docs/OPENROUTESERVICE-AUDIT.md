# OpenRouteService End-to-End Audit

## Architecture

- **Global control:** Super Admin can enable or disable OpenRouteService globally. Credentials remain outside the Super Admin API.
- **Agent scope:** each Agent owns an encrypted API key, routing profile, active override, and distance pricing configuration. The API never returns the key.
- **Origin:** `agent_profiles.latitude/longitude` for the Agent that owns the order.
- **Destination:** selected saved address or checkout coordinates, copied into the order and shipment snapshots.
- **Routing:** one origin and one destination use `POST /v2/directions/{profile}/json`. Matrix is not used because the current flow does not batch destinations.
- **Coordinate conversion:** application/database values remain latitude and longitude; only the ORS adapter converts them to `[longitude, latitude]`.

## Route and pricing flow

1. Laravel validates latitude in `-90..90` and longitude in `-180..180`.
2. Laravel calls ORS with the agent API key in the `Authorization` header.
3. The adapter reads `routes[0].summary.distance` in meters and `duration` in seconds, then derives kilometers.
4. Route results are cached for five minutes by origin, destination, and profile. Pricing is calculated after the cache lookup from the current Agent configuration.
5. Existing pricing remains: below `minimum_distance_km` is free; otherwise distance × price/km with `minimum_charge`; the configured subtotal threshold may also make delivery free.
6. Shipment metadata stores API version, meters, kilometers, duration, profile, coordinates, pricing inputs, applied rule, and final fee.

## Security and failures

- Frontend calls Laravel; it never calls ORS directly and never receives an API key.
- Agent-controlled `base_url` was removed to close a server-side request forgery path. Base URL is server configuration only.
- Logs contain a rounded coordinate pair, profile, status, provider HTTP status, distance/duration, and request time. Authorization headers and response payloads are excluded.
- Invalid credentials, quota/rate limits, timeout, invalid coordinates, missing route summaries, and provider errors are converted to sanitized business errors.
- Haversine is no longer used to charge a final delivery fee. If ORS cannot verify the route, local delivery returns HTTP 422 and no order is created.
- ORS duration is presented as a route estimate, not a real-time traffic ETA.

## Historical behavior

Changing an Agent location, customer address, routing profile, or price configuration after checkout does not mutate an existing shipment. Historical reads use the stored order/shipment coordinates, distance, duration, profile, pricing rule, and fee.
