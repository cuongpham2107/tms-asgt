using Microsoft.AspNetCore.Mvc;
using TMS.Application.DTOs;
using TMS.Application.Services;
using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public class OrdersController(
    IOrderRepository repo,
    IOrderService orderService)
    : BaseODataController<Order, Guid>(repo)
{
    [HttpPost("odata/orders/create-with-trip")]
    public async Task<IActionResult> CreateWithTrip([FromBody] CreateOrderWithTripRequest request, CancellationToken ct)
    {
        var order = await orderService.CreateOrderWithTripAsync(request, ct);
        return Created(order);
    }
}
