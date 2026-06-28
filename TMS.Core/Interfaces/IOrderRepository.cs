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
