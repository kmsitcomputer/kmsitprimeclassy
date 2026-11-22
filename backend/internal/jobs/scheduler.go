package jobs

// Laravel routes/console.php contains only Artisan::command("inspire").
// No application scheduler events are registered. The Go service therefore
// does not run or claim a competing scheduler. This is an intentional port
// of source reality, not a silent omission.
type SchedulerInventory struct {
	ScheduledEvents int
}

func Scheduler() SchedulerInventory {
	return SchedulerInventory{ScheduledEvents: 0}
}
