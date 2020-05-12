<?php

namespace Kinglet\Form;

interface FieldInterface {

	/**
	 * Unique name for this type of template.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Generate and output the field HTML.
	 *
	 * @param $context
	 */
	public function render( $context );

}