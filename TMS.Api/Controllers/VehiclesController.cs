using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public class VehiclesController(IVehicleRepository repo)
    : BaseODataController<Vehicle, Guid>(repo)
{
}
