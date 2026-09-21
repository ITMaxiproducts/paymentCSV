# Agent instructions

## Project documentation

Read the relevant convention document before changing related application behavior.

- [Application architecture](docs/architecture/application-overview.md): Stack, request flow, module responsibilities, public contracts, current scope, and pending phases.
- [Payment report accounting rules](docs/domain/payment-report-accounting.md): Eligible orders and transactions, aggregation, refunds, status semantics, timestamps, and deterministic ordering.
- [Shopify Admin GraphQL integration](docs/integrations/shopify-admin-graphql.md): Store configuration, API query, pagination, transaction filtering, scopes, and integration boundaries.
- [Local development and verification](docs/operations/local-development.md): Runtime requirements, `.env`, environment variables, local server, verification commands, fixtures, and troubleshooting.
- [Protected deployment and operational safety](docs/operations/protected-deployment.md): Public surface, strict request validation, safe errors, retries, diagnostics, CSV hardening, and deployment boundaries.
