# TMS - Agent Guide

## Project

.NET 10.0 ASP.NET Core Web API — Transport Management System (Clean Architecture).

## Architecture

```
TMS.Core/          -- Domain layer, no dependencies
TMS.Application/   -- Application layer (services + DTOs), depends on Core
TMS.Infrastructure/ -- Infrastructure layer, depends on Core
TMS.Api/           -- ASP.NET Web API, depends on Application + Infrastructure
```

- Solution file: `TMS.slnx` (new XML-based `.slnx` format, not legacy `.sln`)
- Launch URL (http): `http://localhost:5233`
- Launch URL (https): `https://localhost:7036`
- All projects target `net10.0` with `<Nullable>enable</Nullable>` and `<ImplicitUsings>enable</ImplicitUsings>`

## Current state

Scaffolded Clean Architecture with EF Core + OData:

```
TMS.Core/
  Entities/            Order, Trip, TripCheckPoint, OrderDelivery, Vehicle
  Interfaces/          IOrderRepository, ITripRepository
TMS.Infrastructure/
  Data/
    AppDbContext        EF DbContext with ApplyConfigurationsFromAssembly
    Configurations/     IEntityTypeConfiguration per entity (Fluent API)
    Repositories/       OrderRepository, TripRepository (return IQueryable)
    Migrations/         EF Core migrations (PostgreSQL via Npgsql)
  DependencyInjection.cs
TMS.Application/
  Services/            IOrderService, OrderService (create order + trip in one tx)
  DTOs/                CreateOrderWithTripRequest, DeliveryRequest
  DependencyInjection.cs
TMS.Api/
  Controllers/         OrdersController, TripsController, VehiclesController
  Program.cs           OData EDM model + Swagger + DI wiring
```

No test projects, no CI, no opencode.json yet.

## Commands

```sh
dotnet build                                     # build all projects
dotnet run --project TMS.Api                     # run the API
dotnet ef migrations add <Name>                  # create migration
  --project TMS.Infrastructure --startup-project TMS.Api
dotnet ef database update                        # apply migration
  --project TMS.Infrastructure --startup-project TMS.Api
```

Swagger UI at `/swagger` in Development.

## Conventions

- Namespace: `TMS.<Layer>` root (e.g. `TMS.Core`, `TMS.Application`)
- C# 14 / .NET 10 conventions apply (file-scoped namespaces, primary constructors, collection expressions)
- Domain entities are pure POCOs in Core — zero EF dependency
- EF config uses Fluent API in `IEntityTypeConfiguration<T>` classes, not data annotations
- Repositories return `IQueryable<T>` for OData query push-down
- Generic repository interface `IRepository<TEntity,TKey>` in Core — controllers inherit from `BaseODataController<TEntity,TKey>` for reusable CRUD
- No test framework chosen yet — if adding tests, default to xUnit unless documented otherwise
- `.slnx` is the solution file — use `dotnet sln TMS.slnx add <project.csproj>` to add projects

## EF Migration

Requires `dotnet-ef` global tool:
```sh
dotnet tool install --global dotnet-ef
```

Design-time factory at `TMS.Infrastructure/Data/DesignTimeDbContextFactory.cs` for migration CLI.

## OData

- OData 9.x with convention-based routing (no `[ODataRoutePrefix]`/`[ODataRoute]` attributes)
- Endpoints at `/odata/{entitySet}`
- Supported: `$filter`, `$orderby`, `$top`, `$skip`, `$expand`, `$select`
- EDM model built via `ODataConventionModelBuilder` in `Program.cs`
- Custom action endpoint: `POST /odata/orders/create-with-trip` (accepts `CreateOrderWithTripRequest`)

## PostgreSQL

- Connection string in `appsettings.json` uses local macOS user, not `postgres` role
- If role "postgres" does not exist, use `whoami` output instead
- DB name: `tms`

<!-- CODEGRAPH_START -->
## CodeGraph

In repositories indexed by CodeGraph (a `.codegraph/` directory exists at the repo root), reach for it BEFORE grep/find or reading files when you need to understand or locate code:

- **MCP tool** (when available): `codegraph_explore` answers most code questions in one call — the relevant symbols' verbatim source plus the call paths between them, including dynamic-dispatch hops grep can't follow. Name a file or symbol in the query to read its current line-numbered source. If it's listed but deferred, load it by name via tool search.
- **Shell** (always works): `codegraph explore "<symbol names or question>"` prints the same output.

If there is no `.codegraph/` directory, skip CodeGraph entirely — indexing is the user's decision.
<!-- CODEGRAPH_END -->
