using Microsoft.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore.Metadata.Builders;
using TMS.Core.Entities;

namespace TMS.Infrastructure.Data.Configurations;

public class TripCheckPointConfiguration : IEntityTypeConfiguration<TripCheckPoint>
{
    public void Configure(EntityTypeBuilder<TripCheckPoint> builder)
    {
        builder.ToTable("TripCheckPoints");
        builder.HasKey(x => x.Id);
        builder.Property(x => x.Id).ValueGeneratedNever();
        builder.Property(x => x.Location).HasMaxLength(200).IsRequired();
        builder.Property(x => x.SequenceNumber).IsRequired();
    }
}
