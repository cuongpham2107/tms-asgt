# TMS — OData + EF Core + PostgreSQL Setup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Set up EF Core with PostgreSQL, OData query support, and initial domain entities for the TMS Clean Architecture project.

**Architecture:** Domain entities (POCOs) in `TMS.Core`, EF configuration in `TMS.Infrastructure` via `IEntityTypeConfiguration` + `AppDbContext`, OData controllers in `TMS.Api`. Core has zero EF dependency.

**Tech Stack:** .NET 10, EF Core 10, Npgsql, ASP.NET Core OData, PostgreSQL

---

## File Structure

```
TMS.Core/
  Entities/
    Order.cs
    Trip.cs
    TripCheckPoint.cs
    OrderDelivery.cs
    Vehicle.cs
  Interfaces/
    IOrderRepository.cs
    ITripRepository.cs

TMS.Infrastructure/
  Data/
    AppDbContext.cs
    Configurations/
      OrderConfiguration.cs
      TripConfiguration.cs
      TripCheckPointConfiguration.cs
      OrderDeliveryConfiguration.cs
      VehicleConfiguration.cs
    Repositories/
      OrderRepository.cs
      TripRepository.cs
  DependencyInjection.cs

TMS.Api/
  Controllers/
    OrdersController.cs
    TripsController.cs
    VehiclesController.cs
```

### Modified files

- `TMS.slnx` — add `TMS.Api` project (missing)
- `TMS.Infrastructure/TMS.Infrastructure.csproj` — add Npgsql package
- `TMS.Api/TMS.Api.csproj` — add OData + EF Design packages
- `TMS.Api/Program.cs` — add OData + EF + DI wiring
- `TMS.Api/appsettings.json` — add connection string
- Delete: `Class1.cs` stubs from Core, Application, Infrastructure

---

### Task 1: Add Api project to solution + fix csproj dependencies

**Files:**
- Modify: `TMS.slnx`
- Modify: `TMS.Api/TMS.Api.csproj`

- [ ] **Step 1: Add TMS.Api to solution**

Edit `TMS.slnx`:

```
<Solution>
  <Project Path="TMS.Api/TMS.Api.csproj" />
  <Project Path="TMS.Application/TMS.Application.csproj" />
  <Project Path="TMS.Core/TMS.Core.csproj" />
  <Project Path="TMS.Infrastructure/TMS.Infrastructure.csproj" />
</Solution>
```

- [ ] **Step 2: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 3: Commit**

```bash
git add TMS.slnx
git commit -m "fix: add TMS.Api project to solution"
```

---

### Task 2: Add EF + OData NuGet packages

**Files:**
- Modify: `TMS.Infrastructure/TMS.Infrastructure.csproj`
- Modify: `TMS.Api/TMS.Api.csproj`

- [ ] **Step 1: Add Npgsql to Infrastructure**

Edit `TMS.Infrastructure/TMS.Infrastructure.csproj` — add after existing `<ProjectReference>`:

```xml
<ItemGroup>
  <PackageReference Include="Npgsql.EntityFrameworkCore.PostgreSQL" Version="10.0.2" />
</ItemGroup>
```

- [ ] **Step 2: Add OData + EF Design to Api**

Edit `TMS.Api/TMS.Api.csproj` — add after the OpenApi package reference:

```xml
<PackageReference Include="Microsoft.AspNetCore.OData" Version="9.3.0" />
<PackageReference Include="Microsoft.EntityFrameworkCore.Design" Version="10.0.2">
  <PrivateAssets>all</PrivateAssets>
  <IncludeAssets>runtime; build; native; contentfiles; analyzers</IncludeAssets>
</PackageReference>
```

- [ ] **Step 3: Verify build**

Run: `dotnet build`
Expected: Build succeeds, packages restored

- [ ] **Step 4: Commit**

```bash
git add TMS.Infrastructure/TMS.Infrastructure.csproj TMS.Api/TMS.Api.csproj
git commit -m "feat: add Npgsql, OData, EF Core packages"
```

---

### Task 3: Create domain entities in TMS.Core

**Files:**
- Create: `TMS.Core/Entities/Order.cs`
- Create: `TMS.Core/Entities/Trip.cs`
- Create: `TMS.Core/Entities/TripCheckPoint.cs`
- Create: `TMS.Core/Entities/OrderDelivery.cs`
- Create: `TMS.Core/Entities/Vehicle.cs`
- Delete: `TMS.Core/Class1.cs`

- [ ] **Step 1: Create Order entity**

`TMS.Core/Entities/Order.cs`:

```csharp
namespace TMS.Core.Entities;

public class Order
{
    public Guid Id { get; set; }
    public string OrderNumber { get; set; } = string.Empty;
    public string? Description { get; set; }
    public string? Origin { get; set; }
    public string? Destination { get; set; }

    public ICollection<OrderDelivery> OrderDeliveries { get; set; } = [];
}
```

- [ ] **Step 2: Create Trip entity**

`TMS.Core/Entities/Trip.cs`:

```csharp
namespace TMS.Core.Entities;

public class Trip
{
    public Guid Id { get; set; }
    public string TripNumber { get; set; } = string.Empty;
    public DateTime? ScheduledStart { get; set; }
    public DateTime? ScheduledEnd { get; set; }

    public Guid? VehicleId { get; set; }
    public Vehicle? Vehicle { get; set; }

    public ICollection<TripCheckPoint> CheckPoints { get; set; } = [];
}
```

- [ ] **Step 3: Create TripCheckPoint entity**

`TMS.Core/Entities/TripCheckPoint.cs`:

```csharp
namespace TMS.Core.Entities;

public class TripCheckPoint
{
    public Guid Id { get; set; }
    public string Location { get; set; } = string.Empty;
    public DateTime? Eta { get; set; }
    public DateTime? ActualArrival { get; set; }
    public int SequenceNumber { get; set; }

    public Guid TripId { get; set; }
    public Trip Trip { get; set; } = null!;
}
```

- [ ] **Step 4: Create OrderDelivery entity**

`TMS.Core/Entities/OrderDelivery.cs`:

```csharp
namespace TMS.Core.Entities;

public class OrderDelivery
{
    public Guid Id { get; set; }
    public string? RecipientName { get; set; }
    public string DeliveryAddress { get; set; } = string.Empty;
    public DateTime? DeliveredAt { get; set; }
    public string? Notes { get; set; }

    public Guid OrderId { get; set; }
    public Order Order { get; set; } = null!;
}
```

- [ ] **Step 5: Create Vehicle entity**

`TMS.Core/Entities/Vehicle.cs`:

```csharp
namespace TMS.Core.Entities;

public class Vehicle
{
    public Guid Id { get; set; }
    public string LicensePlate { get; set; } = string.Empty;
    public string? Model { get; set; }
    public string? DriverName { get; set; }
}
```

- [ ] **Step 6: Delete Class1.cs stub**

Delete: `TMS.Core/Class1.cs`

- [ ] **Step 7: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 8: Commit**

```bash
git add TMS.Core/
git rm TMS.Core/Class1.cs
git commit -m "feat: add domain entities (Order, Trip, TripCheckPoint, OrderDelivery, Vehicle)"
```

---

### Task 4: Create EF configurations + AppDbContext in Infrastructure

**Files:**
- Create: `TMS.Infrastructure/Data/AppDbContext.cs`
- Create: `TMS.Infrastructure/Data/Configurations/OrderConfiguration.cs`
- Create: `TMS.Infrastructure/Data/Configurations/TripConfiguration.cs`
- Create: `TMS.Infrastructure/Data/Configurations/TripCheckPointConfiguration.cs`
- Create: `TMS.Infrastructure/Data/Configurations/OrderDeliveryConfiguration.cs`
- Create: `TMS.Infrastructure/Data/Configurations/VehicleConfiguration.cs`
- Delete: `TMS.Infrastructure/Class1.cs`

- [ ] **Step 1: Create OrderConfiguration**

`TMS.Infrastructure/Data/Configurations/OrderConfiguration.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class OrderConfiguration : IEntityTypeConfiguration<Order>
{
    public void Configure(EntityTypeBuilder<Order> builder)
    {
        builder.ToTable("Orders");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.OrderNumber).HasMaxLength(50).IsRequired();
        builder.HasIndex(x => x.OrderNumber).IsUnique();
        builder.Property(x => x.Description).HasMaxLength(500);
        builder.Property(x => x.Origin).HasMaxLength(200);
        builder.Property(x => x.Destination).HasMaxLength(200);

        builder.HasMany(x => x.OrderDeliveries)
            .WithOne(x => x.Order)
            .HasForeignKey(x => x.OrderId);
    }
}
```

- [ ] **Step 2: Create TripConfiguration**

`TMS.Infrastructure/Data/Configurations/TripConfiguration.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class TripConfiguration : IEntityTypeConfiguration<Trip>
{
    public void Configure(EntityTypeBuilder<Trip> builder)
    {
        builder.ToTable("Trips");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.TripNumber).HasMaxLength(50).IsRequired();
        builder.HasIndex(x => x.TripNumber).IsUnique();

        builder.HasOne(x => x.Vehicle)
            .WithMany()
            .HasForeignKey(x => x.VehicleId)
            .OnDelete(DeleteBehavior.SetNull);

        builder.HasMany(x => x.CheckPoints)
            .WithOne(x => x.Trip)
            .HasForeignKey(x => x.TripId);
    }
}
```

- [ ] **Step 3: Create TripCheckPointConfiguration**

`TMS.Infrastructure/Data/Configurations/TripCheckPointConfiguration.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class TripCheckPointConfiguration : IEntityTypeConfiguration<TripCheckPoint>
{
    public void Configure(EntityTypeBuilder<TripCheckPoint> builder)
    {
        builder.ToTable("TripCheckPoints");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.Location).HasMaxLength(200).IsRequired();
        builder.Property(x => x.SequenceNumber).IsRequired();
    }
}
```

- [ ] **Step 4: Create OrderDeliveryConfiguration**

`TMS.Infrastructure/Data/Configurations/OrderDeliveryConfiguration.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class OrderDeliveryConfiguration : IEntityTypeConfiguration<OrderDelivery>
{
    public void Configure(EntityTypeBuilder<OrderDelivery> builder)
    {
        builder.ToTable("OrderDeliveries");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.RecipientName).HasMaxLength(200);
        builder.Property(x => x.DeliveryAddress).HasMaxLength(500).IsRequired();
        builder.Property(x => x.Notes).HasMaxLength(1000);
    }
}
```

- [ ] **Step 5: Create VehicleConfiguration**

`TMS.Infrastructure/Data/Configurations/VehicleConfiguration.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class VehicleConfiguration : IEntityTypeConfiguration<Vehicle>
{
    public void Configure(EntityTypeBuilder<Vehicle> builder)
    {
        builder.ToTable("Vehicles");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.LicensePlate).HasMaxLength(20).IsRequired();
        builder.HasIndex(x => x.LicensePlate).IsUnique();
        builder.Property(x => x.Model).HasMaxLength(200);
        builder.Property(x => x.DriverName).HasMaxLength(200);
    }
}
```

- [ ] **Step 6: Create AppDbContext**

`TMS.Infrastructure/Data/AppDbContext.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data;

public class AppDbContext(DbContextOptions<AppDbContext> options) : DbContext(options)
{
    public DbSet<Order> Orders => Set<Order>();
    public DbSet<Trip> Trips => Set<Trip>();
    public DbSet<TripCheckPoint> TripCheckPoints => Set<TripCheckPoint>();
    public DbSet<OrderDelivery> OrderDeliveries => Set<OrderDelivery>();
    public DbSet<Vehicle> Vehicles => Set<Vehicle>();

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.ApplyConfigurationsFromAssembly(typeof(AppDbContext).Assembly);
    }
}
```

- [ ] **Step 7: Delete Infrastructure Class1.cs stub**

Delete: `TMS.Infrastructure/Class1.cs`

- [ ] **Step 8: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 9: Commit**

```bash
git add TMS.Infrastructure/
git rm TMS.Infrastructure/Class1.cs
git commit -m "feat: add AppDbContext and EF entity configurations"
```

---

### Task 5: Create repository interfaces + implementations

**Files:**
- Create: `TMS.Core/Interfaces/IOrderRepository.cs`
- Create: `TMS.Core/Interfaces/ITripRepository.cs`
- Create: `TMS.Infrastructure/Data/Repositories/OrderRepository.cs`
- Create: `TMS.Infrastructure/Data/Repositories/TripRepository.cs`

- [ ] **Step 1: Create IOrderRepository**

`TMS.Core/Interfaces/IOrderRepository.cs`:

```csharp
using TMS.Core.Entities;

namespace TMS.Core.Interfaces;

public interface IOrderRepository
{
    IQueryable<Order> Query();
    Task<Order?> GetByIdAsync(Guid id, CancellationToken ct = default);
    void Add(Order order);
    void Update(Order order);
    void Remove(Order order);
    Task SaveChangesAsync(CancellationToken ct = default);
}
```

- [ ] **Step 2: Create ITripRepository**

`TMS.Core/Interfaces/ITripRepository.cs`:

```csharp
using TMS.Core.Entities;

namespace TMS.Core.Interfaces;

public interface ITripRepository
{
    IQueryable<Trip> Query();
    Task<Trip?> GetByIdAsync(Guid id, CancellationToken ct = default);
    void Add(Trip trip);
    void Update(Trip trip);
    void Remove(Trip trip);
    Task SaveChangesAsync(CancellationToken ct = default);
}
```

- [ ] **Step 3: Create OrderRepository**

`TMS.Infrastructure/Data/Repositories/OrderRepository.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using TMS.Core.Entities;
using TMS.Core.Interfaces;
using TMS.Infrastructure.Data;

namespace TMS.Infrastructure.Data.Repositories;

public class OrderRepository(AppDbContext db) : IOrderRepository
{
    public IQueryable<Order> Query() => db.Orders.AsQueryable();

    public async Task<Order?> GetByIdAsync(Guid id, CancellationToken ct = default)
        => await db.Orders.FindAsync([id], ct);

    public void Add(Order order) => db.Orders.Add(order);
    public void Update(Order order) => db.Orders.Update(order);
    public void Remove(Order order) => db.Orders.Remove(order);
    public Task SaveChangesAsync(CancellationToken ct = default)
        => db.SaveChangesAsync(ct);
}
```

- [ ] **Step 4: Create TripRepository**

`TMS.Infrastructure/Data/Repositories/TripRepository.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using TMS.Core.Entities;
using TMS.Core.Interfaces;
using TMS.Infrastructure.Data;

namespace TMS.Infrastructure.Data.Repositories;

public class TripRepository(AppDbContext db) : ITripRepository
{
    public IQueryable<Trip> Query() => db.Trips.AsQueryable();

    public async Task<Trip?> GetByIdAsync(Guid id, CancellationToken ct = default)
        => await db.Trips.FindAsync([id], ct);

    public void Add(Trip trip) => db.Trips.Add(trip);
    public void Update(Trip trip) => db.Trips.Update(trip);
    public void Remove(Trip trip) => db.Trips.Remove(trip);
    public Task SaveChangesAsync(CancellationToken ct = default)
        => db.SaveChangesAsync(ct);
}
```

- [ ] **Step 5: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 6: Commit**

```bash
git add TMS.Core/Interfaces/ TMS.Infrastructure/Data/Repositories/
git commit -m "feat: add repository interfaces and implementations"
```

---

### Task 6: Create DependencyInjection extensions

**Files:**
- Create: `TMS.Infrastructure/DependencyInjection.cs`
- Delete: `TMS.Application/Class1.cs`

- [ ] **Step 1: Create Infrastructure DI**

`TMS.Infrastructure/DependencyInjection.cs`:

```csharp
using Microsoft.EntityFrameworkCore;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using TMS.Core.Interfaces;
using TMS.Infrastructure.Data;
using TMS.Infrastructure.Data.Repositories;

namespace TMS.Infrastructure;

public static class DependencyInjection
{
    public static IServiceCollection AddInfrastructure(
        this IServiceCollection services,
        IConfiguration configuration)
    {
        services.AddDbContextPool<AppDbContext>(options =>
            options.UseNpgsql(configuration.GetConnectionString("DefaultConnection")));

        services.AddScoped<IOrderRepository, OrderRepository>();
        services.AddScoped<ITripRepository, TripRepository>();

        return services;
    }
}
```

- [ ] **Step 2: Delete Application Class1.cs stub**

Delete: `TMS.Application/Class1.cs`

- [ ] **Step 3: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 4: Commit**

```bash
git add TMS.Infrastructure/DependencyInjection.cs
git rm TMS.Application/Class1.cs
git commit -m "feat: add Infrastructure DI registration"
```

---

### Task 7: Wire up OData + EF in Program.cs

**Files:**
- Modify: `TMS.Api/Program.cs`
- Modify: `TMS.Api/appsettings.json`

- [ ] **Step 1: Update appsettings.json**

`TMS.Api/appsettings.json`:

```json
{
  "Logging": {
    "LogLevel": {
      "Default": "Information",
      "Microsoft.AspNetCore": "Warning"
    }
  },
  "AllowedHosts": "*",
  "ConnectionStrings": {
    "DefaultConnection": "Host=localhost;Database=tms;Username=postgres;Password=postgres"
  }
}
```

- [ ] **Step 2: Rewrite Program.cs**

`TMS.Api/Program.cs`:

```csharp
using Microsoft.AspNetCore.OData;
using Microsoft.OData.Edm;
using Microsoft.OData.ModelBuilder;
using TMS.Core.Entities;
using TMS.Infrastructure;

static IEdmModel GetEdmModel()
{
    var builder = new ODataConventionModelBuilder();
    builder.EntitySet<Order>("Orders");
    builder.EntitySet<Trip>("Trips");
    builder.EntitySet<Vehicle>("Vehicles");
    return builder.GetEdmModel();
}

var builder = WebApplication.CreateBuilder(args);

builder.Services.AddInfrastructure(builder.Configuration);
builder.Services.AddControllers()
    .AddOData(options => options
        .AddRouteComponents("odata", GetEdmModel())
        .EnableQueryFeatures());

builder.Services.AddOpenApi();

var app = builder.Build();

if (app.Environment.IsDevelopment())
{
    app.MapOpenApi();
}

app.UseHttpsRedirection();
app.MapControllers();
app.Run();
```

- [ ] **Step 3: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 4: Commit**

```bash
git add TMS.Api/Program.cs TMS.Api/appsettings.json
git commit -m "feat: wire up OData, EF, and DI in API startup"
```

---

### Task 8: Create OData controllers

**Files:**
- Create: `TMS.Api/Controllers/OrdersController.cs`
- Create: `TMS.Api/Controllers/TripsController.cs`
- Create: `TMS.Api/Controllers/VehiclesController.cs`

- [ ] **Step 1: Create OrdersController**

`TMS.Api/Controllers/OrdersController.cs`:

```csharp
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

[ODataRoutePrefix("Orders")]
public class OrdersController(IOrderRepository repo) : ODataController
{
    [EnableQuery]
    [ODataRoute]
    public IActionResult Get()
    {
        return Ok(repo.Query());
    }

    [EnableQuery]
    [ODataRoute("({key})")]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var order = await repo.GetByIdAsync(key, ct);
        return order is null ? NotFound() : Ok(order);
    }

    public async Task<IActionResult> Post([FromBody] Order order, CancellationToken ct)
    {
        repo.Add(order);
        await repo.SaveChangesAsync(ct);
        return Created(order);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Order updated, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Update(updated);
        await repo.SaveChangesAsync(ct);
        return Updated(updated);
    }

    public async Task<IActionResult> Delete([FromRoute] Guid key, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Remove(existing);
        await repo.SaveChangesAsync(ct);
        return NoContent();
    }
}
```

- [ ] **Step 2: Create TripsController**

`TMS.Api/Controllers/TripsController.cs`:

```csharp
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

[ODataRoutePrefix("Trips")]
public class TripsController(ITripRepository repo) : ODataController
{
    [EnableQuery]
    [ODataRoute]
    public IActionResult Get()
    {
        return Ok(repo.Query());
    }

    [EnableQuery]
    [ODataRoute("({key})")]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var trip = await repo.GetByIdAsync(key, ct);
        return trip is null ? NotFound() : Ok(trip);
    }

    public async Task<IActionResult> Post([FromBody] Trip trip, CancellationToken ct)
    {
        repo.Add(trip);
        await repo.SaveChangesAsync(ct);
        return Created(trip);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Trip updated, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Update(updated);
        await repo.SaveChangesAsync(ct);
        return Updated(updated);
    }

    public async Task<IActionResult> Delete([FromRoute] Guid key, CancellationToken ct)
    {
        var existing = await repo.GetByIdAsync(key, ct);
        if (existing is null) return NotFound();
        repo.Remove(existing);
        await repo.SaveChangesAsync(ct);
        return NoContent();
    }
}
```

- [ ] **Step 3: Create VehiclesController**

`TMS.Api/Controllers/VehiclesController.cs`:

```csharp
using Microsoft.AspNetCore.Mvc;
using Microsoft.AspNetCore.OData.Query;
using Microsoft.AspNetCore.OData.Routing.Controllers;
using TMS.Core.Entities;
using TMS.Infrastructure.Data;

namespace TMS.Api.Controllers;

[ODataRoutePrefix("Vehicles")]
public class VehiclesController(AppDbContext db) : ODataController
{
    [EnableQuery]
    [ODataRoute]
    public IActionResult Get()
    {
        return Ok(db.Vehicles.AsQueryable());
    }

    [EnableQuery]
    [ODataRoute("({key})")]
    public async Task<IActionResult> Get([FromRoute] Guid key, CancellationToken ct)
    {
        var vehicle = await db.Vehicles.FindAsync([key], ct);
        return vehicle is null ? NotFound() : Ok(vehicle);
    }

    public async Task<IActionResult> Post([FromBody] Vehicle vehicle, CancellationToken ct)
    {
        db.Vehicles.Add(vehicle);
        await db.SaveChangesAsync(ct);
        return Created(vehicle);
    }

    public async Task<IActionResult> Put([FromRoute] Guid key, [FromBody] Vehicle updated, CancellationToken ct)
    {
        var existing = await db.Vehicles.FindAsync([key], ct);
        if (existing is null) return NotFound();
        db.Vehicles.Update(updated);
        await db.SaveChangesAsync(ct);
        return Updated(updated);
    }

    public async Task<IActionResult> Delete([FromRoute] Guid key, CancellationToken ct)
    {
        var existing = await db.Vehicles.FindAsync([key], ct);
        if (existing is null) return NotFound();
        db.Vehicles.Remove(existing);
        await db.SaveChangesAsync(ct);
        return NoContent();
    }
}
```

- [ ] **Step 4: Verify build**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 5: Commit**

```bash
git add TMS.Api/Controllers/
git commit -m "feat: add OData controllers for Orders, Trips, Vehicles"
```

---

### Task 9: Add EF migration

**Files:**
- Generated: `TMS.Infrastructure/Data/Migrations/`

- [ ] **Step 1: Ensure dotnet-ef tool is installed**

```sh
dotnet tool install --global dotnet-ef 2>/dev/null || true
```

- [ ] **Step 2: Create initial migration**

```sh
dotnet ef migrations add InitialCreate \
  --project TMS.Infrastructure \
  --startup-project TMS.Api
```

Expected: Migration files created in `TMS.Infrastructure/Data/Migrations/`

- [ ] **Step 3: Verify build after migration**

Run: `dotnet build`
Expected: Build succeeds

- [ ] **Step 4: Commit**

```bash
git add TMS.Infrastructure/Data/Migrations/
git commit -m "feat: add initial EF migration"
```

---

### Task 10: Remove Class1.cs from Application (if still present)

- [ ] **Step 1: Confirm Application has no remaining stub**

Run: `ls TMS.Application/`
Expected: Empty directory (no Class1.cs)

- [ ] **Step 2: Remove directory placeholder if needed**

Run: `rmdir TMS.Application/ 2>/dev/null; ls TMS.Application/`
Expected: No output (directory may still exist but empty — .csproj needs at least one file)

Note: The Application layer has no files yet. Keep the project reference — it will be used when business logic services are added later.

---

## Spec Coverage Check

| Spec requirement | Task |
|---|---|
| Domain entities (Order, Trip, TripCheckPoint, OrderDelivery, Vehicle) | Task 3 |
| EF configurations via IEntityTypeConfiguration | Task 4 |
| AppDbContext with ApplyConfigurationsFromAssembly | Task 4 |
| Repositories returning IQueryable | Task 5 |
| Infrastructure DI extension | Task 6 |
| OData EDM model + route registration | Task 7 |
| OData controllers with [EnableQuery] | Task 8 |
| PostgreSQL connection string in appsettings | Task 7 |
| EF migration | Task 9 |
| No EF dependency in Core | All — Core has only POCOs + interfaces |
