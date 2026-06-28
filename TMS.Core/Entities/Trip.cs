namespace TMS.Core.Entities;

public class Trip
{
    public Guid Id { get; set; }
    public string TripNumber { get; set; } = string.Empty;
    public DateTime? ScheduledStart { get; set; }
    public DateTime? ScheduledEnd { get; set; }

    public Guid? VehicleId { get; set; }
    public Vehicle? Vehicle { get; set; }

    public ICollection<TripCheckPoint> CheckPoints { get; set; } = [];
}
