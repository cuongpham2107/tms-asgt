using TMS.Core.Entities;

namespace TMS.Core.Interfaces;

public interface IOrderRepository : IRepository<Order, Guid>
{
}
