namespace TMS.Core.Entities;

public class TripCheckPoint
{
    public Guid Id { get; set; }
    public string Location { get; set; } = string.Empty;
    public DateTime? Eta { get; set; }
    public DateTime? ActualArrival { get; set; }
    public int SequenceNumber { get; set; }

    public Guid TripId { get; set; }
    public Trip Trip { get; set; } = null!;
}
