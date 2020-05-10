<?php

namespace Kinglet\Template;

interface EngineInterface {

	/**
	 * Locate a template by suggestions and render it along with the given context.
	 *
	 * @param array $suggestions
	 * @param array $context
	 *
	 * @return string
	 */
	public function render( $suggestions, $context = [] );

}