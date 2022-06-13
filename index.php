<?php

error_log( "Received a request." );

require "./config.php";
require "./lib/amazon-alexa-php/src/autoload.php";
require "./lib/state.php";

ob_start();

try {
	// Generate the right type of Request object
	$request = \Alexa\Request\Request::fromHTTPRequest( APPLICATION_ID );

	$response = new \Alexa\Response\Response;

	// By default, always end the session unless there's a reason not to.
	$response->shouldEndSession = true;

	$user_id = $request->data['session']['user']['userId'];
	$state = get_state( $user_id );

	switch ( $request->data['request']['type'] ) {
		case 'LaunchRequest':
			// Just opening the skill ("Open Bible Quizzing").
			$response = handleIntent( $request, $response, 'Launch' );
		break;
		case 'SessionEndedRequest':
			// Nothing to do.
		break;
		case 'IntentRequest':
			if ( strpos( $request->intentName, "AMAZON." ) !== 0 ) {
				// Remove $state->intent_on_yes and $state->vars_on_yes on the first non-Yes/No request after they're set.
				unset(
					$state->intent_on_yes,
					$state->vars_on_yes
				);
			}

			$response = handleIntent( $request, $response );
			break;
		case 'CanFulfillIntentRequest':
			// @todo
		break;
	}

	if ( $response->shouldEndSession ) {
		unset(
			$state->last_response,
			$state->chapter,
			$state->book,
			$state->intent_on_yes,
			$state->vars_on_yes,
			$state->last_five_verses
		);
	}
	else {
		$state->last_response = $response;
	}

	save_state( $user_id, $state );

	$json = json_encode( $response->render() );

	echo $json;
} catch ( Exception $e ) {
	error_log( var_export( $e, true ) );

	header( "HTTP/1.1 400 Bad Request" );
	exit;
}

/**
 * Given an intent, handle all processing and response generation.
 *
 * @param object $request The Request.
 * @param object $response The Response.
 * @param string $intent The intent to handle, regardless of $request->intentName
 */
function handleIntent( $request, $response, $intent = null ) {
	global $state;

	if ( ! $intent ) {
		$intent = $request->intentName;
	}

	if ( ! $request->session->new ) {
		switch ( $intent ) {
			case 'AMAZON.StopIntent':
			case 'AMAZON.CancelIntent':
				$response = stats( $response );
				return $response;
			break;
		}
	}

	switch ( $intent ) {
		case 'Launch':
		case 'AMAZON.HelpIntent':
			$response->shouldEndSession = false;

			$response->addOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );

			$response->addCardTitle( "Using Bible Quizzing" );
			$response->addCardOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );
		break;
		case 'Stats':
			$response = stats( $response, true );
		break;
		case 'ResetStats':
			$state->right = 0;
			$state->wrong = 0;

			$response->addOutput( "Ok, I've reset your stats." );
		break;
		case 'ChooseBook':
			$response->shouldEndSession = false;

			// slots: Book
			$book = $request->getSlot( 'Book' );

			if ( ! get_book_object( $book ) ) {
				$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
				$response->shouldEndEssion = true;
				return $response;
			}

			$state->book = $book;

			unset( $state->chapter );
			unset( $state->last_five_verses );

			$response->addOutput( "How do you want to learn? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );

			$response->addCardTitle( "Choose a Mode" );
			$response->addCardOutput( "How do you want to learn? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );
		break;
		case 'ChooseBookAndChapter':
			$response->shouldEndSession = false;

			// slots: Book, Chapter
			// slots: Book
			$book = $request->getSlot( 'Book' );

			if ( ! get_book_object( $book ) ) {
				$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
				$response->shouldEndEssion = true;
				return $response;
			}

			$state->book = $book;

			$chapter = $request->getSlot( 'Chapter' );
			$state->chapter = $chapter;

			$response->addOutput( "How do you want to learn " . $book . " chapter " . $chapter . "? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );

			$response->addCardTitle( "Choose a Mode" );
			$response->addCardOutput( "How do you want to learn? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );
		break;
		case 'ReadToMe':
			$response->shouldEndSession = false;

			// slots: Book, Chapter
			if ( $request->getSlot( 'Book' ) ) {
				if ( ! get_book_object( $request->getSlot( 'Book' ) ) ) {
					$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
					$response->shouldEndEssion = true;
					return $response;
				}

				$state->book = $request->getSlot( 'Book' );

				if ( ! $request->getSlot( 'Chapter' ) ) {
					$state->chapter = 1;
				}
			}

			if ( $request->getSlot( 'Chapter' ) ) {
				$state->chapter = $request->getSlot( 'Chapter' );
			}

			if ( ! $state->book ) {
				$books = glob( "data/*.json" );

				$state->book = str_replace( ".json", "", $books[0] );
			}

			$response = read_to_me( $response );
		break;
		case 'InWhich':
			$response->shouldEndSession = false;

			$response = in_which( $response );

			$state->intent_on_continue = 'InWhich';
		break;
		case 'FillInTheBlank':
			$response->shouldEndSession = false;

			$response = fill_in_the_blank( $response );

			$state->intent_on_continue = 'FillInTheBlank';
		break;
		case 'SingleNumberResponse':
			$response->shouldEndSession = false;

			// slots: Number
			if ( isset( $state->expected_response ) ) {
				switch ( $state->expected_response ) {
					case 'verse':
						if ( $state->verse == $request->getSlot( 'Number' ) ) {
							$response->addOutput( "That's right!" );
							right();
						}
						else {
							$response->addOutput( "Sorry, the answer was verse " . $state->verse . ", not " . $request->getSlot( 'Number' ) );
							wrong();
						}

						unset( $state->chapter );

						return handleIntent( $request, $response, $state->intent_on_continue );
					break;
					case 'chapter_and_verse':
						$response->addOutput( "I need the chapter AND verse." );
					break;
				}
			}
			else {
				$response->addOutput( "I'm sorry, I don't know what you're telling me." );
			}
		break;
		case 'SingleLetterResponse':
			$response->shouldEndSession = false;

			$letter = strtoupper( substr( $request->getSlot( "Letter" ), 0, 1 ) ); // Alexa will send "B." instead of "B".

			// slots: Letter
			if ( strtoupper( $state->letter ) == $letter ) {
				$response->addOutput( "That's right!" );
				right();
			}
			else {
				$response->addOutput( "Sorry, the answer was " . strtoupper( $state->letter ) . ", not " . $letter . "." );
				wrong();
			}

			unset( $state->letter );

			return handleIntent( $request, $response, $state->intent_on_continue );
		break;
		case 'ChapterAndVerseResponse':
			$response->shouldEndSession = false;

			// slots: Chapter, Verse
			if ( isset( $state->expected_response ) ) {
				switch ( $state->expected_response ) {
					case 'chapter_and_verse':
					case 'verse':
						if ( $state->verse == $request->getSlot( 'Verse' ) && $state->chapter == $request->getSlot( 'Chapter' ) ) {
							$response->addOutput( "That's right!" );
							right();
						}
						else {
							$response->addOutput( "Sorry, the answer was chapter " . $state->chapter . ", verse " . $state->verse . "." );
							wrong();
						}

						if ( 'chapter_and_verse' === $state->expected_respone ) {
							unset( $state->chapter );
						}

						return handleIntent( $request, $response, $state->intent_on_continue );
						break;
				}
			}
			else {
				$response->addOutput( "I'm sorry, I don't know what you're telling me." );
			}
		break;
		case 'AMAZON.YesIntent':
			if ( isset( $state->intent_on_yes ) ) {
				if ( isset( $state->vars_on_yes ) ) {
					foreach ( $state->vars_on_yes as $name => $val ) {
						$state->{ $name } = $val;
					}
				}

				return handleIntent( $request, $response, $state->intent_on_yes );
			}
			else {
				$response->addOutput( "I don't know what you're agreeing to." );
			}
		break;
		case 'AMAZON.NoIntent':
			if ( isset( $state->intent_on_yes ) ) {
				switch ( $state->intent_on_yes ) {
					case 'ReadToMe':
						$response->addOutput( "Ok, good bye." );
					break;
				}
			}
			else {
				$response->addOutput( "I don't know what you're saying no to." );
			}
		break;
		case 'AMAZON.RepeatIntent':
			if ( ! $state || ! $state->last_response ) {
				$response->addOutput( "I'm sorry, I don't know what to repeat." );
			}
			else {
				$response->output = $state->last_response->output;
			}
		break;
		case 'AMAZON.FallbackIntent':
			$response->addOutput( "I'm sorry, I couldn't understand that. Can you repeat it?" );
			$response->shouldEndSession = false;
		break;

		default:
			$response->addOutput( "I don't know what you're asking me to do." );
			error_log( "Couldn't handle intent: " . $intent );
		break;
	}

	return $response;
}

$output = ob_get_clean();

header( 'Content-Type: application/json' );
echo $output;

exit;

/**
 * Output a chapter of the currently selected book.
 *
 * @param Response $response
 * @return Response
 */
function read_to_me( $response ) {
	global $state;

	$book = get_book_object( $state->book );

	if ( ! $book ) {
		$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
		$response->shouldEndEssion = true;
		return $response;
	}

	if ( ! isset( $state->chapter ) ) {
		$state->chapter = 1;
	}

	if ( $state->chapter > count( $book->chapters ) ) {
		$response->addOutput( $book . " doesn't have a chapter " . $state->chapter . "." );
	}

	$chapter = $book->chapters[ $state->chapter - 1 ];

	$response->addOutput( $book->book . ", chapter " . $state->chapter . ". " );

	foreach ( $chapter as $verse_index => $verse ) {
		$response->addOutput( "Verse " . ( $verse_index + 1 ) . ": " . $verse );
	}

	if ( $state->chapter != count( $book->chapters ) ) {
		$response->addOutput( "Thus ends " . $book->book . " chapter " . $state->chapter . ". Shall I continue reading?" );

		$state->intent_on_yes = 'ReadToMe';
		$state->vars_on_yes = array( 'book' => $state->book, 'chapter' => $state->chapter + 1 );
	}
	else {
		$response->addOutput( "Thus ends the book of " . $book->book . "." );
		$response->shouldEndSession = true;
	}


	return $response;
}

/**
 * Quiz the listener about the reference for a verse.
 *
 * @param Response $response
 * @return Response
 */
function in_which( $response ) {
	global $state;

	$book = get_book_object( $state->book );

	if ( ! $book ) {
		$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
		$response->shouldEndEssion = true;
		return $response;
	}

	// Ensure that we don't ask the same verse twice within five responses.
	if ( ! isset( $state->last_five_verses ) ) {
		$state->last_five_verses = array();
	}

	do {
		if ( isset( $state->chapter ) ) {
			$chosen_chapter = $state->chapter;
			$state->expected_response = 'verse';
		}
		else {
			$chosen_chapter = rand( 1, count( $book->chapters ) );
			$state->expected_response = 'chapter_and_verse';
		}

		$chapter = $book->chapters[ $chosen_chapter - 1 ];

		$chosen_verse = rand( 1, count( $chapter ) );
	} while ( in_array( $chosen_chapter . ":" . $chosen_verse, $state->last_five_verses ) );

	array_unshift( $state->last_five_verses, $chosen_chapter . ":" . $chosen_verse );
	$state->last_five_verses = array_slice( $state->last_five_verses, 0, 5 );

	if ( isset( $state->chapter ) ) {
		$response->addOutput( "Which verse is this in chapter " . $state->chapter . "?" );
	}
	else {
		$response->addOutput( "Which chapter and verse is this?" );
	}

	$state->chapter = $chosen_chapter;
	$state->verse = $chosen_verse;

	$response->addOutput( $chapter[ $state->verse - 1 ] );

	return $response;
}

/**
 * @param Response $response
 * @return Response
 */
function fill_in_the_blank( $response ) {
	global $state;

	$book = get_book_object( $state->book );

	if ( ! $book ) {
		$response->addOutput( "I'm sorry, I haven't finished reading that book yet." );
		$response->shouldEndEssion = true;
		return $response;
	}

	// Ensure that we don't ask the same verse twice within five responses.
	if ( ! isset( $state->last_five_verses ) ) {
		$state->last_five_verses = array();
	}

	do {
		if ( isset( $state->chapter ) ) {
			$chosen_chapter = $state->chapter;
			$state->expected_response = 'verse';
		}
		else {
			$chosen_chapter = rand( 1, count( $book->chapters ) );
			$state->expected_response = 'chapter_and_verse';
		}

		$chapter = $book->chapters[ $chosen_chapter - 1 ];

		$chosen_verse = rand( 1, count( $chapter ) );
	} while ( in_array( $chosen_chapter . ":" . $chosen_verse, $state->last_five_verses ) );

	array_unshift( $state->last_five_verses, $chosen_chapter . ":" . $chosen_verse );
	$state->last_five_verses = array_slice( $state->last_five_verses, 0, 5 );

	$response->addOutput( "Fill in the blank in chapter " . $chosen_chapter . " verse " . $chosen_verse . ": " );

	$verse_text = $chapter[ $chosen_verse - 1 ];
	$verse_words = preg_split( "/\s/", $verse_text );

	$number_of_words = rand( 1, 3 );

	$location = rand( 0, count( $verse_words ) - 1 - $number_of_words );
	$answer = array_slice( $verse_words, $location, $number_of_words );

	for ( $i = 0; $i < $number_of_words; $i++ ) {
		$verse_words[ $i + $location ] = " BLANK ";
	}

	if ( $location === 0 ) {
		$prefix = "";
	}
	else {
		$prefix = $verse_words[ $location - 1 ];
	}

	$actual_answer = join( " ", $answer );
	$answer_options = fill_in_the_blank_options( $book, $prefix, $number_of_words, 3, $actual_answer );
	$answer_options[] = $actual_answer;

	$verse_with_blank = join( " ", $verse_words );

	$response->addOutput( $verse_with_blank );
	$state->letter = "A";

	$response->addOutput( "Is it..." );

	shuffle( $answer_options );

	foreach ( array( "A", "B", "C", "D" ) as $choice_index => $choice ) {
		$response->addOutput( $choice . ": " . $answer_options[ $choice_index ] );

		if ( $actual_answer === $answer_options[ $choice_index ] ) {
			$state->letter = $choice;
		}
	}

	return $response;
}

function fill_in_the_blank_options( $book, $prefix, $number_of_words, $number_of_answers, $actual_answer ) {
	$possible_answers = array();

	foreach ( $book->chapters as $chapter ) {
		foreach ( $chapter as $verse ) {
			$verse_words = preg_split( "/\s/", $verse );

			if ( $prefix === "" ) {
				$possible_answer = join( " ", array_slice( $verse_words, 0, $number_of_words ) );

				if ( $possible_answer != $actual_answer ) {
					$possible_answers[] = $possible_answer;
				}
			}

			foreach ( $verse_words as $idx => $word ) {
				if ( $word === $prefix ) {
					$possible_answer = join( " ", array_slice( $verse_words, $idx + 1, $number_of_words ) );

					if ( $possible_answer != $actual_answer ) {
						$possible_answers[] = $possible_answer;
					}
				}
			}
		}
	}

	$possible_answers = array_unique( $possible_answers );

	while ( count( $possible_answers ) < $number_of_answers ) {
		$random_chapter = $book->chapters[ rand( 0, count( $book->chapters ) - 1 ) ];
		$random_verse = $random_chapter[ rand( 0, count( $random_chapter ) - 1 ) ];
		$random_verse_words = preg_split( "/\s/", $random_verse );
		$random_starting_point = max( 0, rand( 0, count( $random_verse_words ) - 1 - $number_of_words ) );

		$random_possible_answer = join( " ", array_slice( $random_verse_words, $random_starting_point, $number_of_words ) );

		if ( $random_possible_answer != $actual_answer ) {
			$possible_answers[] = $possible_answer;
		}

		$possible_answers = array_unique( $possible_answers );
	}

	shuffle( $possible_answers );

	return array_slice( $possible_answers, 0, $number_of_answers );
}

function right() {
	global $state;

	if ( isset( $state->right ) ) {
		$state->right++;
	}
	else {
		$state->right = 1;
	}
}

function wrong() {
	global $state;

	if ( isset( $state->wrong ) ) {
		$state->wrong++;
	}
	else {
		$state->wrong = 1;
	}
}

function stats( $response, $force = false ) {
	global $state;

	$right = $state->right ?? 0;
	$wrong = $state->wrong ?? 0;

	$total = $right + $wrong;

	if ( ! $total ) {
		if ( $force ) {
			$response->addOutput( "You haven't answered any questions yet." );
		}
	}
	else {
		if ( $total === 1 ) {
			$response->addOutput( "You've correctly answered " . $right . " out of " . $total . " question so far." );
		}
		else {
			$response->addOutput( "You've correctly answered " . $right . " out of " . $total . " questions so far." );
		}
	}

	return $response;
}

function get_book_object( $book_name ) {
	$book_name = preg_replace( '/\s+/', '-', $book_name );
	$book_name = preg_replace( '/[^a-z0-9-]/', '', $book_name );

	$file_path = "data/" . $book_name . ".json";

	if ( ! file_exists( $file_path ) ) {
		return false;
	}

	$book = json_decode( file_get_contents( $file_path ) );

	return $book;
}