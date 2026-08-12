<?php
/**
 * Registers the audio script provider with prc-content-transformer.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

use PRC\Platform\Audio_Narration\Providers\Audio_Script_Provider;
use PRC\Platform\Content_Transformer\Providers\Provider;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;

/**
 * Loading seam between this plugin and the content transformer.
 *
 * Audio_Script_Provider implements an interface owned by prc-content-transformer.
 * Declaring that class while the transformer is inactive would fatal, so the
 * class file is only required inside the transformer's own registration hook,
 * which by definition cannot fire unless the transformer is loaded.
 */
class Script_Provider_Registrar {

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The hook loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( 'prc_content_transformer_register_providers', $this, 'register_provider' );
		$this->loader->add_filter( 'prc_content_transformer_system_instruction', $this, 'filter_system_instruction', 10, 2 );
	}

	/**
	 * Register the audio script provider with the transformer's registry.
	 *
	 * @return void
	 */
	public function register_provider() {
		if ( ! interface_exists( Provider::class ) || ! class_exists( Provider_Registry::class ) ) {
			return;
		}

		require_once PRC_AUDIO_NARRATION_DIR . '/includes/providers/class-audio-script-provider.php';

		Provider_Registry::register( new Audio_Script_Provider() );
	}

	/**
	 * Replace the transformer's default system instruction for audio scripts.
	 *
	 * The default instruction forbids modifying substantive content and limits
	 * the model to restructuring. That is right for Apple News and email, where
	 * the text is reproduced verbatim in a new container -- but it is wrong
	 * here. Writing "62 percent" for "62%", expanding an acronym, and replacing
	 * a table with a spoken comparison are all modifications the default
	 * instruction prohibits, and they are the entire point of this provider.
	 *
	 * @param string   $instruction The default system instruction.
	 * @param Provider $provider    The target provider.
	 * @return string
	 */
	public function filter_system_instruction( $instruction, $provider ) {
		if ( ! $provider instanceof Provider || Audio_Script_Provider::SLUG !== $provider->get_slug() ) {
			return $instruction;
		}

		$narration_instruction = 'You are a narration script writer for Pew Research Center. Your task is to rewrite Markdown-formatted research content as a script to be read aloud by a text-to-speech engine.

CRITICAL RULES:
- Preserve every statistic, finding, attribution, and the original ordering exactly. The numbers themselves must never change.
- Do NOT summarize or condense. The script should be comparable in length to the source.
- Do NOT add analysis, interpretation, framing, or commentary that is not in the source.
- DO rewrite for the ear: verbalize symbols and abbreviations, expand acronyms, fold headings into spoken transitions, and convert tables, charts, and lists into spoken prose.
- Never refer to anything the listener cannot see. There is no chart, no figure, no table, and no link.
- Output ONLY the script, with no markup, no preamble, and no explanatory text.';

		$format_spec = $provider->get_format_spec();
		if ( ! empty( $format_spec ) ) {
			$narration_instruction .= "\n\nTARGET FORMAT SPECIFICATION:\n\n" . $format_spec;
		}

		return $narration_instruction;
	}
}
