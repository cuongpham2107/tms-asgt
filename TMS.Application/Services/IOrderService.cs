using TMS.Application.DTOs;
using TMS.Core.Entities;

namespace TMS.Application.Services;

public interface IOrderService
{
    Task<Order> CreateOrderWithTripAsync(CreateOrderWithTripRequest request, CancellationToken ct = default);
}
