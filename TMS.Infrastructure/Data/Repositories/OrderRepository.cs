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
