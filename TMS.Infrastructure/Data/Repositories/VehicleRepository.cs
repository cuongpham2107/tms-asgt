using Microsoft.EntityFrameworkCore;
using TMS.Core.Entities;
using TMS.Core.Interfaces;
using TMS.Infrastructure.Data;

namespace TMS.Infrastructure.Data.Repositories;

public class VehicleRepository(AppDbContext db) : IVehicleRepository
{
    public IQueryable<Vehicle> Query() => db.Vehicles.AsQueryable();

    public async Task<Vehicle?> GetByIdAsync(Guid id, CancellationToken ct = default)
        => await db.Vehicles.FindAsync([id], ct);

    public void Add(Vehicle vehicle) => db.Vehicles.Add(vehicle);
    public void Update(Vehicle vehicle) => db.Vehicles.Update(vehicle);
    public void Remove(Vehicle vehicle) => db.Vehicles.Remove(vehicle);
    public Task SaveChangesAsync(CancellationToken ct = default)
        => db.SaveChangesAsync(ct);
}
