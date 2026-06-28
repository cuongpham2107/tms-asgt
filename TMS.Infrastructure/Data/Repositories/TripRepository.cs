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
