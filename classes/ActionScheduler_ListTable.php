<?php

/**
 * Implements the admin view of the actions.
 */
class ActionScheduler_ListTable extends PP_List_Table {
	/**
	 * The package name.
	 */
	protected $package = 'action-scheduler';

	/**
	 * Columns to show (name => label).
	 */
	protected $columns = array();

	/**
	 * Actions (name => label).
	 */
	protected $row_actions = array();

	/**
	 *  The active data stores
	 */
	protected $stores;

	/**
	 * Bulk actions. The key of the array is the method name of the implementation:
	 *
	 *     bulk_<key>(array $ids, string $sql_in).
	 *
	 * See the comments in the parent class for further details
	 */
	protected $bulk_actions = array();

	/**
	 * Flag variable to render our notifications, if any, once.
	 */
	protected static $did_notification = false;


	/**
	 * Sets the current data store object into `store->action` and initialises the object.
	 */
	public function __construct() {

		$this->maybe_render_admin_notices();

		$this->bulk_actions = array(
			'delete' => __( 'Delete', 'action-scheduler' ),
		);

		$this->columns = array(
			'hook'   => __( 'Hook', 'action-scheduler' ),
			'status' => __( 'Status', 'action-scheduler' ),
			'args'   => __( 'Arguments', 'action-scheduler' ),
			'group'  => __( 'Group', 'action-scheduler' ),
			'recurrence' => __( 'Recurrence', 'action-scheduler' ),
			'scheduled'  => __( 'Scheduled Date', 'action-scheduler' ),
			'log'        => __( 'Log', 'action-scheduler' ),
		);

		$this->row_actions = array(
			'hook' => array(
				'run' => array(
					__( 'Run', 'action-scheduler' ),
					__( 'Process the action now as if it were run as part of a queue', 'action-scheduler' ),
				),
			),
		);

		$this->stores = (object) array(
			'action' => ActionScheduler_Store::instance(),
			'log'    => ActionScheduler_Logger::instance(),
		);

		parent::__construct( array(
			'singular' => __( 'action-scheduler', 'action-scheduler' ),
			'plural'   => __( 'action-scheduler', 'action-scheduler' ),
			'ajax'     => false,
		) );
	}

	/**
	 * Convert a interval of seconds into a two part human friendly string.
	 *
	 * The WordPress human_time_diff() function only calculates the time difference to one degree, meaning
	 * even if an action is 1 day and 11 hours away, it will display "1 day". This funciton goes one step
	 * further to display two degrees of accuracy.
	 *
	 * Based on Crontrol::interval() funciton by Edward Dale: https://wordpress.org/plugins/wp-crontrol/
	 *
	 * @param int $interval A interval in seconds.
	 * @return string A human friendly string representation of the interval.
	 */
	private static function human_interval( $interval ) {

		// array of time period chunks
		$chunks = array(
			array( 60 * 60 * 24 * 365 , _n_noop( '%s year', '%s years', 'action-scheduler' ) ),
			array( 60 * 60 * 24 * 30 , _n_noop( '%s month', '%s months', 'action-scheduler' ) ),
			array( 60 * 60 * 24 * 7, _n_noop( '%s week', '%s weeks', 'action-scheduler' ) ),
			array( 60 * 60 * 24 , _n_noop( '%s day', '%s days', 'action-scheduler' ) ),
			array( 60 * 60 , _n_noop( '%s hour', '%s hours', 'action-scheduler' ) ),
			array( 60 , _n_noop( '%s minute', '%s minutes', 'action-scheduler' ) ),
			array(  1 , _n_noop( '%s second', '%s seconds', 'action-scheduler' ) ),
		);

		if ( $interval <= 0 ) {
			return __( 'Now!', 'action-scheduler' );
		}

		// Step one: the first chunk
		for ( $i = 0, $j = count( $chunks ); $i < $j; $i++ ) {
			$seconds = $chunks[$i][0];
			$name = $chunks[$i][1];

			if ( ( $count = floor( $interval / $seconds ) ) != 0 ) {
				break;
			}
		}

		$output = sprintf( _n( $name[0], $name[1], $count, 'action-scheduler' ), $count );

		if ( $i + 1 < $j ) {
			$seconds2 = $chunks[$i + 1][0];
			$name2 = $chunks[$i + 1][1];

			if ( ( $count2 = floor( ( $interval - ( $seconds * $count ) ) / $seconds2 ) ) != 0 ) {
				// add to output var
				$output .= ' '.sprintf( _n( $name2[0], $name2[1], $count2, 'action-scheduler' ), $count2 );
			}
		}

		return $output;
	}

	/**
	 * Returns the recurrence of an action or 'Non-repeating'. The output is human readable.
	 *
	 * @param ActionScheduler_Action $item
	 *
	 * @return string
	 */
	protected function get_recurrence( $item ) {
		$recurrence = $item->get_schedule();
		if ( method_exists( $recurrence, 'interval_in_seconds' ) ) {
			return self::human_interval( $recurrence->interval_in_seconds() );
		}
		return __( 'Non-repeating', 'action-scheduler' );
	}

	/**
	 * Serializes the argument of an action to render it in a human friendly format.
	 *
	 * @param array $row The array representation of the current row of the table
	 *
	 * @return string
	 */
	public function column_args( array $row ) {
		return '<code>' . json_encode( $row['args'] ) . '</code>';
	}

	/**
	 * Prints the comments, which are the log entries. It needs to be named comments otherwise it won't pickup
	 * the WordPress styles to make the number pretty.
	 *
	 * It will also render all the log entries, but it will be hidden and rendered on click in the number as a modal.
	 *
	 * @param array $row Action array.
	 */
	public function column_log( array $row ) {
		echo '<ol>';
		$timezone = new DateTimezone( 'UTC' );
		foreach ( $row['log'] as $log ) {
			$date = $log->get_date();
			$date->setTimezone( $timezone );
			echo '<li><strong>' . esc_html( $date->format( 'Y-m-d H:i:s e' ) ) . '</strong><br/>' . esc_html( $log->get_message() ) . '</li>';
		}
		echo '</ol>';
	}

	/**
	 * Renders admin notifications
	 *
	 * Notifications:
	 *  1. When the maximum number of tasks are being executed simultaneously
	 *  2. Notifications when a task us manually executed
	 */
	public function maybe_render_admin_notices() {
		if ( self::$did_notification ) {
			return;
		}

		self::$did_notification = true;

		if ( ActionScheduler_Store::instance()->get_claim_count() >= apply_filters( 'action_scheduler_queue_runner_concurrent_batches', 5 ) ) : ?>
<div id="message" class="updated">
	<p><?php printf( __( 'Maximum simulatenous batches already in progress (%s queues). No actions will be processed until the current batches are complete.', 'action-scheduler' ), ActionScheduler_Store::instance()->get_claim_count() ); ?></p>
</div>
		<?php endif;
		$notification = get_transient( 'actionscheduler_admin_executed' );
		if ( is_array( $notification ) ) {
			delete_transient( 'actionscheduler_admin_executed' );

			$action = ActionScheduler::store()->fetch_action( $notification['action_id'] );
			$action_hook_html = '<strong>' . $action->get_hook() . '</strong>';
			if ( 1 == $notification['success'] ): ?>
				<div id="message" class="updated">
					<p><?php printf( __( 'Successfully executed the action: %s', 'action-scheduler' ), $action_hook_html ); ?></p>
				</div>
			<?php else : ?>
			<div id="message" class="error">
				<p><?php printf( __( 'Could not execute the action: %s', 'action-scheduler' ), $action_hook_html ); ?></p>
			</div>
			<?php endif;
		}
	}


	/**
	 * Prints the scheduled date in a human friendly format.
	 *
	 * @param array $row The array representation of the current row of the table
	 */
	public function column_scheduled( $row ) {
		$next_timestamp = $row['scheduled']->next()->format( 'U' );
		echo $row['scheduled']->next()->format( 'Y-m-d H:i:s' );
		if ( gmdate( 'U' ) > $next_timestamp ) {
			printf( __( ' (%s ago)', 'action-scheduler' ), human_time_diff( gmdate( 'U' ), $next_timestamp ) );
		} else {
			echo ' (' . human_time_diff( gmdate( 'U' ), $next_timestamp ) . ')';
		}
	}

	/**
	 * Bulk delete
	 *
	 * Deletes actions based on their ID. This is the handler for the bulk delete. It assumes the data
	 * properly validated by the callee and it will delete the actions without any extra validation.
	 *
	 * @param array $ids
	 *
	 * @return void
	 */
	protected function bulk_delete( array $ids, $ids_sql ) {
		foreach ( $ids as $id ) {
			$this->stores->action->delete_action( $id );
		}
	}

	/**
	 * Implements the logic behind running an action. PP_Table_List validates the request and their
	 * parameters are valid.
	 */
	protected function row_action_run( $action_id ) {
		try {
			ActionScheduler::runner()->process_action( $action_id );
			$success = 1;
		} catch ( Exception $e ) {
			$success = 0;
		}

		set_transient( 'actionscheduler_admin_executed', compact( 'action_id', 'success' ), 30 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function prepare_items() {
		$this->process_bulk_action();

		$this->process_row_actions();

		if ( ! empty( $_REQUEST['_wp_http_referer'] ) ) {
			// _wp_http_referer is used only on bulk actions, we remove it to keep the $_GET shorter
			wp_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce' ), wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
			exit;
		}

		$this->prepare_column_headers();

		$per_page = $this->get_items_per_page( $this->package . '_items_per_page', $this->items_per_page );
		$query = array(
			'per_page' => $per_page,
			'offset'   => $this->get_items_offset(),
			'status'   => $this->get_request_status(),
			'orderby'  => 'modified',
			'order'    => 'ASC',
		);

		$this->items = array();

		$total_items = $this->stores->action->query_actions_count( $query );

		foreach ( $this->stores->action->query_actions( $query ) as $id ) {
			$item = $this->stores->action->fetch_action( $id );
			$this->items[ $id ] = array(
				'ID'     => $id,
				'hook'   => $item->get_hook(),
				'status' => ActionScheduler::store()->get_status( $id ),
				'args'   => $item->get_args(),
				'group'  => $item->get_group(),
				'log'    => $this->stores->log->get_logs( $id ),
				'recurrence' => $this->get_recurrence( $item ),
				'scheduled'  => $item->get_schedule(),
			);
		}

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		) );
	}

	/**
	 * This function is used to filter the actions by a status. It will return the status based on a
	 * a GET parameter or it will filter by 'pending' by default.
	 * We filter by status because if we do not filter we have NullActions that are not rendered at the
	 * moment.
	 *
	 * @return string
	 */
	public function get_request_status() {
		$statuses = array(
			ActionScheduler_Store::STATUS_PENDING,
			ActionScheduler_Store::STATUS_COMPLETE,
			ActionScheduler_Store::STATUS_FAILED,
			ActionScheduler_Store::STATUS_RUNNING,
		);

		if ( ! empty( $_GET['status'] ) && in_array( $_GET['status'], $statuses ) ) {
			return $_GET['status'];
		}

		return ActionScheduler_Store::STATUS_PENDING;
	}

	/**
	 * Prints the available statuses so the user can click to filter.
	 *
	 * @return void
	 */
	public function display_filter_by_status() {
		$statuses = array(
			'pending'   => ActionScheduler_Store::STATUS_PENDING,
			'running'   => ActionScheduler_Store::STATUS_RUNNING,
			'complete'  => ActionScheduler_Store::STATUS_COMPLETE,
			'failed'    => ActionScheduler_Store::STATUS_FAILED,
		);

		$li = array();
		foreach ( $statuses as $name => $status ) {
			$total_items = $this->stores->action->query_actions_count( compact( 'status' ) );
			if ( 0 === $total_items ) {
				continue;
			}

			if ( $status === $this->get_request_status() ) {
				$li[] =  '<li class="' . esc_attr( $name ) . '">'
					. '<strong>'
						. esc_html( ucfirst( $name ) )
					. "</strong> (" . absint( $total_items ) . ")"
				. '</li>';
				continue;
			}

			$li[] =  '<li class="' . esc_attr( $name ) . '">'
				. '<a href="' . esc_url( add_query_arg( 'status', $status ) )  . '">'
				. esc_html( ucfirst( $name ) )
				. "</a> ($total_items)"
			. '</li>';
		}

		if ( $li ) {
			echo '<ul class="subsubsub">';
			echo implode( " | \n", $li );
			echo '</ul>';
		}
	}

	/**
	 * Overrides the original display method to print the `display_filter_by_status()`. By overriding
	 * this object it prints all the needed HTML already, making it easy to use from higher layers because
	 * the object is 'self-contained' and 'self-sufficient'.
	 *
	 * @return void
	 */
	public function display() {
		$this->display_filter_by_status();
		parent::display();
	}
}
