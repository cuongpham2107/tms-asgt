# TMS — Transport Management System

.NET 10 ASP.NET Core Web API quản lý vận tải, thiết kế theo Clean Architecture.

## Architecture

```
TMS.Core/          Domain layer, entities, interfaces        không phụ thuộc gì
TMS.Application/   Services + DTOs, business logic           phụ thuộc Core
TMS.Infrastructure/ EF Core, repositories, migrations         phụ thuộc Core
TMS.Api/           Controllers, OData, Swagger, DI wiring    phụ thuộc App + Infra
```

Mỗi controller kế thừa `BaseODataController<TEntity, TKey>` — CRUD tự động, chỉ cần inject repository.

## Tech Stack

- .NET 10, C# 14 (file-scoped namespaces, primary constructors, collection expressions)
- ASP.NET Core OData 9.x (convention routing, `$filter`/`$expand`/`$select`)
- Entity Framework Core 10 + Npgsql (PostgreSQL)
- Swagger UI (Swashbuckle 10.x)

## Quick Start

**Yêu cầu:** .NET 10 SDK, PostgreSQL, `dotnet-ef` global tool.

```sh
# Cài dotnet-ef nếu chưa có
dotnet tool install --global dotnet-ef

# Tạo database
dotnet ef database update --project TMS.Infrastructure --startup-project TMS.Api

# Chạy API
dotnet run --project TMS.Api
```

Truy cập:
- Swagger UI: `http://localhost:5233/swagger`
- API: `http://localhost:5233/odata/{entitySet}`

## API Endpoints

| Method | Endpoint | Mô tả |
|---|---|---|
| GET | `/odata/orders` | Danh sách orders (hỗ trợ OData query) |
| GET | `/odata/orders({key})` | Order theo ID |
| POST | `/odata/orders` | Tạo order đơn giản |
| PUT | `/odata/orders({key})` | Cập nhật order |
| DELETE | `/odata/orders({key})` | Xóa order |
| POST | `/odata/orders/create-with-trip` | Tạo order + trip + check points trong 1 transaction |
| GET | `/odata/trips` | Danh sách trips |
| GET | `/odata/vehicles` | Danh sách vehicles |

OData query: `/odata/orders?$filter=contains(orderNumber,'ORD')&$expand=orderDeliveries`

## Database (PostgreSQL)

Connection string ở `TMS.Api/appsettings.json`. Mặc định dùng user macOS hiện tại:
```json
"DefaultConnection": "Host=localhost;Database=tms;Username=cuongpham"
```

### Migration

```sh
dotnet ef migrations add <Tên> --project TMS.Infrastructure --startup-project TMS.Api
dotnet ef database update --project TMS.Infrastructure --startup-project TMS.Api
```

## Domain Entities

```
Order ──→ OrderDelivery  (1-n)
Trip  ──→ TripCheckPoint (1-n)
Vehicle                    (standalone)
```

Entity là POCOs thuần (Core) — EF config qua `IEntityTypeConfiguration<T>` ở Infrastructure.
