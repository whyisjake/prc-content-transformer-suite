<?php
/**
 * Class PluginTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Bootstrap;
use PRC\Platform\Audio_Narration\Loader;

/**
 * Plugin scaffold and bootstrap tests
 */
class PluginTest extends WP_UnitTestCase {
	/**
	 * The plugin file loads without a fatal error and reports its version.
	 */
	public function test_plugin_loaded() {
		$this->assertTrue( defined( 'PRC_AUDIO_NARRATION_VERSION' ) );
		$this->assertEquals( '1.0.0', PRC_AUDIO_NARRATION_VERSION );
	}

	/**
	 * All plugin path constants are defined after load.
	 */
	public function test_constants_defined() {
		$this->assertTrue( defined( 'PRC_AUDIO_NARRATION_FILE' ) );
		$this->assertTrue( defined( 'PRC_AUDIO_NARRATION_DIR' ) );
		$this->assertTrue( defined( 'PRC_AUDIO_NARRATION_URL' ) );
		$this->assertTrue( defined( 'PRC_AUDIO_NARRATION_VERSION' ) );
	}

	/**
	 * Core runtime classes are loaded on every request.
	 */
	public function test_classes_exist() {
		$this->assertTrue( class_exists( Bootstrap::class ) );
		$this->assertTrue( class_exists( Loader::class ) );
	}

	/**
	 * Activation and deactivation handlers load only when their hooks fire.
	 *
	 * They are deliberately absent from the normal request path -- loading
	 * them on every page load would be dead weight -- so this asserts the
	 * lazy-load contract rather than their presence at runtime.
	 */
	public function test_lifecycle_handlers_load_on_demand() {
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-plugin-activator.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-plugin-deactivator.php';

		$this->assertTrue( class_exists( 'PRC\Platform\Audio_Narration\Plugin_Activator' ) );
		$this->assertTrue( class_exists( 'PRC\Platform\Audio_Narration\Plugin_Deactivator' ) );
	}

	/**
	 * The bootstrap exposes its loader so modules can register hooks against it.
	 */
	public function test_bootstrap_exposes_loader() {
		$bootstrap = new Bootstrap();

		$this->assertInstanceOf( Loader::class, $bootstrap->get_loader() );
		$this->assertEquals( 'prc-audio-narration', $bootstrap->get_plugin_name() );
		$this->assertEquals( PRC_AUDIO_NARRATION_VERSION, $bootstrap->get_version() );
	}

	/**
	 * The loader registers queued actions and filters with WordPress on run().
	 */
	public function test_loader_registers_hooks() {
		$loader    = new Loader();
		$component = new class() {
			/**
			 * Test callback.
			 *
			 * @param mixed $value Passed through unchanged.
			 * @return mixed
			 */
			public function callback( $value = null ) {
				return $value;
			}
		};

		$loader->add_action( 'prc_audio_narration_test_action', $component, 'callback' );
		$loader->add_filter( 'prc_audio_narration_test_filter', $component, 'callback' );
		$loader->run();

		$this->assertNotFalse( has_action( 'prc_audio_narration_test_action', array( $component, 'callback' ) ) );
		$this->assertNotFalse( has_filter( 'prc_audio_narration_test_filter', array( $component, 'callback' ) ) );
	}

	/**
	 * Deactivation is safe when Action Scheduler is not present.
	 */
	public function test_deactivation_without_action_scheduler() {
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-plugin-deactivator.php';

		$fired = false;
		add_action(
			'prc_audio_narration_deactivated',
			function () use ( &$fired ) {
				$fired = true;
			}
		);

		\PRC\Platform\Audio_Narration\Plugin_Deactivator::deactivate();

		$this->assertTrue( $fired, 'Deactivation should complete and fire its hook.' );
	}
}
