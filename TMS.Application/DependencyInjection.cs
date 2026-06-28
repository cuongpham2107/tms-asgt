using Microsoft.Extensions.DependencyInjection;
using TMS.Application.Services;

namespace TMS.Application;

public static class DependencyInjection
{
    public static IServiceCollection AddApplication(this IServiceCollection services)
    {
        services.AddScoped<IOrderService, OrderService>();
        return services;
    }
}
