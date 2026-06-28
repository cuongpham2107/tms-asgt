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
