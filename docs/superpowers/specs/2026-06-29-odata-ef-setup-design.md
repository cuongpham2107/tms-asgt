# TMS — OData + EF Core + PostgreSQL Setup

## Overview

Add OData query support, Entity Framework Core with PostgreSQL, and EF migrations to the existing TMS Clean Architecture scaffold.

## Architecture

```
TMS.Core/              POCO entities, repository interfaces, enums
TMS.Infrastructure/    AppDbContext, IEntityTypeConfiguration, repository impls
TMS.Application/       Service layer (thin, optional)
TMS.Api/               OData controllers, DI wiring, startup
```

### Key constraint: Core has zero EF dependency

Entities are pure POCOs in `TMS.Core/Entities/`. EF configuration (table mapping, indexes, relationships) is defined via `IEntityTypeConfiguration<T>` in `TMS.Infrastructure/Data/Configurations/`.

## Domain Entities (TMS.Core/Entities/)

- `Order`
- `Trip`
- `TripCheckPoint`
- `OrderDelivery`
- `Vehicle`

Relationships refined during implementation. Seed structure:

```
Order ──> OrderDelivery  (1-n)
Trip  ──> TripCheckPoint (1-n)
Vehicle                    (standalone)
```

## Infrastructure Layer

### NuGet packages (Infrastructure)

- `Npgsql.EntityFrameworkCore.PostgreSQL`

### AppDbContext

In `TMS.Infrastructure/Data/AppDbContext`. `OnModelCreating` calls `ApplyConfigurationsFromAssembly` to load all `IEntityTypeConfiguration<T>` classes.

### Entity Configurations

Each entity has a dedicated class in `TMS.Infrastructure/Data/Configurations/`:

- `OrderConfiguration.cs`
- `TripConfiguration.cs`
- `TripCheckPointConfiguration.cs`
- `OrderDeliveryConfiguration.cs`
- `VehicleConfiguration.cs`

All use Fluent API — no data annotations.

### Repositories

Interface in `TMS.Core/Interfaces/`, implementation in `TMS.Infrastructure/Data/Repositories/`.

- `IOrderRepository` / `OrderRepository`
- `ITripRepository` / `TripRepository`

Repository methods return `IQueryable<T>` so OData query push-down reaches EF/SQL.

### Dependency Injection

Extension method `AddInfrastructure()` registers `DbContext`, repositories, and connection string.

## API Layer

### NuGet packages (Api)

- `Microsoft.AspNetCore.OData`
- `Microsoft.EntityFrameworkCore.Design` (for migrations CLI)

### OData Configuration

EDM model built via `Microsoft.OData.ModelBuilder` in `Program.cs`. Each entity set registered explicitly:

```csharp
var builder = new ODataConventionModelBuilder();
builder.EntitySet<Order>("Orders");
builder.EntitySet<Trip>("Trips");
builder.EntitySet<Vehicle>("Vehicles");
```

Controllers inherit `ODataController`, use `[ODataRoutePrefix]` attribute routing.

```csharp
[ODataRoutePrefix("Orders")]
public class OrdersController : ODataController
{
    [EnableQuery]
    public IActionResult Get(ODataQueryOptions<Order> options) { ... }
}
```

### Query Flow

```
HTTP GET /odata/orders?$filter=tripId eq 1&$expand=trip
  → OData middleware parses query
  → Controller.Get() returns IQueryable<Order>
  → OData applies $filter/$expand/$top/$skip to IQueryable
  → EF translates to SQL
  → PostgreSQL executes
  → OData serializes response
```

### Error Responses

Standard OData error format via `ODataError`.

## Database

- **Provider:** PostgreSQL via Npgsql
- **Connection string:** `appsettings.json` / User Secrets
- **Pooling:** `AddDbContextPool<AppDbContext>`
- **Migrations:** EF Core Migrations, stored in `TMS.Infrastructure/Data/Migrations/`

### Migration commands

```sh
dotnet ef migrations add InitialCreate \
  --project TMS.Infrastructure \
  --startup-project TMS.Api

dotnet ef database update \
  --project TMS.Infrastructure \
  --startup-project TMS.Api
```

Requires `dotnet-ef` global tool:
```sh
dotnet tool install --global dotnet-ef
```

## Testing

No test project yet. xUnit will be added later when chosen.

## Out of Scope (this iteration)

- Auth/identity
- Detailed business logic
- Integration tests
- Containerization
