using TMS.Core.Entities;
using TMS.Core.Interfaces;

namespace TMS.Api.Controllers;

public class TripsController(ITripRepository repo)
    : BaseODataController<Trip, Guid>(repo)
{
}
