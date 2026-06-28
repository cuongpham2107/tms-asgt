namespace TMS.Core.Entities;

public class Vehicle
{
    public Guid Id { get; set; }
    public string LicensePlate { get; set; } = string.Empty;
    public string? Model { get; set; }
    public string? DriverName { get; set; }
}
