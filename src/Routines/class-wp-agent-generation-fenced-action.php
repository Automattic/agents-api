<?php
/**
 * Action Scheduler action fenced by a persisted routine schedule generation.
 *
 * Action Scheduler is an optional runtime dependency: this file declares
 * nothing when its base class is unavailable, so the bootstrap can require it
 * unconditionally on sites without Action Scheduler.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI\Routines;

defined( 'ABSPATH' ) || exit;

if ( class_exists( '\ActionScheduler_Action' ) ) {

	/**
	 * A fetched routine action that no-ops when its stamped generation no
	 * longer matches the routine's current generation.
	 *
	 * Two fences in one wrapper:
	 *
	 *  - {@see execute()} refuses to fire the scheduled hook when the stamped
	 *    generation is stale, so a superseded or orphaned scheduled action
	 *    never wakes the routine.
	 *  - {@see get_schedule()} reports a canceled (non-recurring) schedule when
	 *    stale, so Action Scheduler's queue runner never clones a successor
	 *    from a superseded recurring action in the same process.
	 */
	class WP_Agent_Generation_Fenced_Action extends \ActionScheduler_Action {

		private string $routine_id;
		private string $expected_generation;

		/**
		 * @param string                    $hook                Action hook.
		 * @param array<array-key,mixed>    $args                Stamped action args.
		 * @param \ActionScheduler_Schedule $schedule            Original schedule.
		 * @param string                    $group               Action group.
		 * @param string                    $routine_id          Routine the action belongs to.
		 * @param string                    $expected_generation Generation stamped into the args ('' when unstamped).
		 */
		public function __construct(
			string $hook,
			array $args,
			\ActionScheduler_Schedule $schedule,
			string $group,
			string $routine_id,
			string $expected_generation
		) {
			parent::__construct( $hook, $args, $schedule, $group );
			$this->routine_id          = $routine_id;
			$this->expected_generation = $expected_generation;
		}

		/**
		 * Fire the scheduled hook only while the stamped generation is current.
		 * A fenced (stale) action no-ops and reports through
		 * `agents_routine_action_fenced` so consumers can observe the skip.
		 */
		public function execute(): void {
			if ( $this->is_generation_current() ) {
				parent::execute();
				return;
			}

			/**
			 * Fires when a fetched routine action refused to execute because
			 * its stamped generation is no longer the routine's current
			 * generation.
			 *
			 * @param string                            $routine_id          Routine id.
			 * @param string                            $expected_generation Stamped generation that lost the fence.
			 * @param WP_Agent_Generation_Fenced_Action $action              The fenced action.
			 */
			do_action( 'agents_routine_action_fenced', $this->routine_id, $this->expected_generation, $this );
		}

		/**
		 * Report a canceled schedule when the generation is stale so the queue
		 * runner never repeats a superseded recurring action.
		 *
		 * @return \ActionScheduler_Schedule
		 */
		public function get_schedule(): \ActionScheduler_Schedule {
			$schedule = parent::get_schedule();
			if ( $this->is_generation_current() ) {
				return $schedule;
			}

			$date = $schedule->get_date();
			return new \ActionScheduler_CanceledSchedule(
				$date instanceof \DateTime ? $date : new \DateTime( 'now', new \DateTimeZone( 'UTC' ) )
			);
		}

		public function get_routine_id(): string {
			return $this->routine_id;
		}

		public function get_expected_generation(): string {
			return $this->expected_generation;
		}

		/**
		 * The fence is current only while a non-empty persisted generation
		 * exactly matches the generation stamped into the action args. When
		 * the option layer is absent (non-WordPress harness) there is nothing
		 * to fence against and execution proceeds.
		 */
		private function is_generation_current(): bool {
			if ( ! function_exists( 'get_option' ) ) {
				return true;
			}

			$current = get_option( WP_Agent_Routine_Action_Scheduler_Bridge::generation_option_name( $this->routine_id ), '' );
			return '' !== $this->expected_generation
				&& is_string( $current )
				&& '' !== $current
				&& hash_equals( $this->expected_generation, $current );
		}
	}
}
