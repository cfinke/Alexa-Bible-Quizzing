<?php

namespace Alexa\Response;

class Response {
	public $version = '1.0';
	public $sessionAttributes = array();

	public $outputSpeech = null;
	public $card = null;
	public $reprompt = null;
	public $shouldEndSession = false;

	public $output = array();
	public $reprompt_output = array();

	public $cardTitle = '';
	public $cardOutput = array();

	public function __construct() {
		$this->outputSpeech = new OutputSpeech;
	}

	public function addOutput( $text ) {
		$this->output[] = $text;

		return $this;
	}

	public function addCardTitle( $title ) {
		$this->cardTitle = $title;

		return $this;
	}

	public function addCardOutput( $text ) {
		$this->cardOutput[] = $text;

		return $this;
	}

	/**
	 * @deprecated
	 */
	public function respond() { return $this; }

	/**
	 * Set up response with SSML.
	 * @param string $ssml
	 * @return \Alexa\Response\Response
	 */
	public function respondSSML() {
		$this->outputSpeech = new OutputSpeech;
		$this->outputSpeech->type = 'SSML';
		$this->outputSpeech->ssml = join( "\n\n", $this->output );

		return $this;
	}

	/**
	 * Add text to the reprompt.
	 *
	 * @param string $text
	 * @param bool $reset If true, erase previous reprompt text.
	 * @return \Alexa\Response\Response
	 */
	public function reprompt( $text, $reset = false ) {
		if ( $reset ) {
			$this->reprompt_output = array();
		}

		$this->reprompt_output[] = $text;

		return $this;
	}

	/**
	 * Set up reprompt with given ssml
	 * @param string $ssml
	 * @return \Alexa\Response\Response
	 */
	public function repromptSSML($ssml) {
		$this->reprompt = new Reprompt;
		$this->reprompt->outputSpeech->type = 'SSML';
		$this->reprompt->outputSpeech->text = $ssml;

		return $this;
	}

	/**
	 * Add card information
	 * @param string $title
	 * @param string $content
	 * @return \Alexa\Response\Response
	 */
	public function withCard($title, $content = '') {
		$this->card = new Card;
		$this->card->title = $title;
		$this->card->content = $content;

		return $this;
	}

	/**
	 * Set if it should end the session
	 * @param type $shouldEndSession
	 * @return \Alexa\Response\Response
	 */
	public function endSession($shouldEndSession = true) {
		$this->shouldEndSession = $shouldEndSession;

		return $this;
	}

	/**
	 * Add a session attribute that will be passed in every requests.
	 * @param string $key
	 * @param mixed $value
	 */
	public function addSessionAttribute($key, $value) {
		$this->sessionAttributes[$key] = $value;
	}

	/**
	 * Return the response as an array for JSON-ification
	 * @return type
	 */
	public function render() {
		$this->outputSpeech = new OutputSpeech;
		$this->outputSpeech->type = 'SSML';
		$this->outputSpeech->ssml = '<speak>' . join( "\n\n", $this->output ) . '</speak>';

		if ( ! empty( $this->reprompt_output ) ) {
			$this->reprompt = new Reprompt;
			$this->reprompt->outputSpeech->type = 'SSML';
			$this->reprompt->outputSpeech->ssml = '<speak>' . join( "\n\n", $this->reprompt_output ) . '</speak>';
		}

		if ( $this->cardTitle || ! empty( $this->cardOutput ) ) {
			$this->card = new Card;
			$this->card->title = $this->cardTitle;
			$this->card->content = join( "\n\n", $this->cardOutput );
		}

		return array(
			'version' => $this->version,
			'sessionAttributes' => $this->sessionAttributes,
			'response' => array(
				'outputSpeech' => $this->outputSpeech ? $this->outputSpeech->render() : null,
				'card' => $this->card ? $this->card->render() : null,
				'reprompt' => $this->reprompt ? $this->reprompt->render() : null,
				'shouldEndSession' => $this->shouldEndSession ? true : false
			)
		);
	}
}