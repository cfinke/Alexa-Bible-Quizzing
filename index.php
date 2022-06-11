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

	if ( 'LaunchRequest' === $request->data['request']['type'] ) {
		// Just opening the skill ("Open Bible Quizzing").
		$response = handleIntent( $request, $response, 'Launch' );
	}
	else {
		if ( strpos( $request->intentName, "AMAZON." ) !== 0 ) {
			// Remove $state->intent_on_yes and $state->vars_on_yes on the first non-Yes/No request after they're set.
			unset(
				$state->intent_on_yes,
				$state->vars_on_yes
			);
		}

		$response = handleIntent( $request, $response );
	}

	if ( $response->shouldEndSession ) {
		unset(
			$state->last_response,
			$state->chapter,
			$state->book,
			$state->intent_on_yes,
			$state->vars_on_yes
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

	if ( ! $request->session->new ) {
		switch ( $intent ) {
			case 'AMAZON.StopIntent':
			case 'AMAZON.CancelIntent':
				return;
			break;
		}
	}

	if ( ! $intent ) {
		$intent = $request->intentName;
	}

	switch ( $intent ) {
		case 'Launch':
		case 'AMAZON.HelpIntent':
			$response->shouldEndSession = false;

			$response->addOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );

			$response->addCardTitle( "Using Bible Quizzing" );
			$response->addCardOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );
		break;
		case 'ChooseBook':
			$response->shouldEndSession = false;

			// slots: Book
			$book = $request->getSlot( 'Book' );
			$state->book = $book;

			$response->addOutput( "How do you want to learn? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );

			$response->addCardTitle( "Choose a Mode" );
			$response->addCardOutput( "How do you want to learn? You can say 'Fill in the blank', 'In which verse', or 'Read to me.'" );
		break;
		case 'ChooseBookAndChapter':
			$response->shouldEndSession = false;

			// slots: Book, Chapter
			// slots: Book
			$book = $request->getSlot( 'Book' );
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

		/*
		case 'FillInTheBlank':
			$response->shouldEndSession = false;
		break;
		case 'InWhich':
			$response->shouldEndSession = false;
		break;
		*/
	}

	/*
	switch ( $intent ) {
		case 'QuizMe':
			$book = $request->getSlot( 'Book' );

			if ( ! $book ) {
				$book = $state->book;
			}

			if ( ! $book ) {
				$response->addOutput( "Which book would you like to learn?" );
			}
			else {
				$chapter = $request->getSlot( 'Chapter' );

				$response = quiz_me( $response, "inwhich", $book, $chapter );

				$state->last_entry_request = $request;
				$state->last_response = $response;
			}

			$response->shouldEndSession = false;
		break;
		case 'QuizMeChapter':
			$book = $state->book;

			$chapter = $request->getSlot( 'Chapter' );

			$response = quiz_me( $response, "inwhich", $book, $chapter );

			$state->last_entry_request = $request;
			$state->last_response = $response;

			$response->shouldEndSession = false;
		break;
		case 'SingleNumberResponse':
			$response->addOutput( "Response: " . $request->getSlot( 'Number' ) );

			if ( $state->expected_response === 'verse' ) {
				if ( $state->verse === $request->getSlot( 'Number' ) ) {
					$response->addOutput( "That's right!" );
				}
				else {
					$response->addOutput( "Sorry, the answer was verse " . $state->verse );
				}

				$response->addOutput( "Do you want to keep going?" );
			}
			else {
				$response->addOutput( "I need a chapter AND a verse." );
			}

			$state->last_response = $response;

			$response->shouldEndSession = false;
		break;
		case 'ChapterAndVerseResponse':
			$chapter = $request->getSlot( 'Chapter' );
			$verse = $request->getSlot( 'Verse' );

			$response->addOutput( "Chapter and Verse Response: " . $request->getSlot( 'Chapter' ) . " verse " . $request->getSlot( 'Verse' ) . "" );

			if ( $state->chapter == $chapter && $state->verse == $verse ) {
				$response->addOutput( "That's right!" );
			}
			else {
				$response->addOutput( "Sorry, the answer was chapter " . $state->chapter . " verse " . $state->verse . "." );
			}

			$response->addOutput( "Do you want to keep going?" );

			$state->last_response = $response;

			$response->shouldEndSession = false;
		break;
		case 'FillInTheBlankResponse':
			$response->addOutput( "You filled in the blank: " . $request->getSlot( "Answer" ) );

			$state->last_response = $response;

			$response->shouldEndSession = false;
		break;
		case 'AMAZON.FallbackIntent':
			$response->addOutput( "I'm sorry, I couldn't understand that. Can you repeat it?" );
			$response->shouldEndSession = false;
		break;
		case 'Quizzing':
		case 'AMAZON.HelpIntent':
			$response->addOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );

			$response->addCardTitle( "Using Bible Quizzing" );
			$response->addCardOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );

			$state->last_response = $response;

			$response->shouldEndSession = false;

			// Options:
			// Name this verse.
			// Finish this verse.
			// Recite this verse.
			// Read to me.
			// Fill in the blank.

		break;
		case 'AMAZON.RepeatIntent':
			if ( ! $state || ! $state->last_response ) {
				$response->addOutput( "I'm sorry, I don't know what to repeat." );
			}
			else {
				$response->output = $state->last_response->output;
			}
		break;
		default:
			$response->addOutput( "I don't know which intent this is: " . $intent );
			error_log( "Couldn't handle " . $intent );

			$state->last_response = $response;
		break;
	}
	*/

	return $response;
}

$output = ob_get_clean();

header( 'Content-Type: application/json' );
echo $output;

exit;

function quiz_me( $response, $mode, $book, $chapter = null ) {
	global $state;

	$book = strtolower( $book );

	if ( file_exists( "data/" . $book . ".json" ) ) {
		$book = json_decode( file_get_contents( "data/" . $book . ".json" ) );

		switch( $mode ) {
			case "inwhich":
				if ( $chapter ) {
					$state->expected_response = "verse";
				}
				else {
					$state->expected_response = "chapter_and_verse";
				}

				if ( $chapter ) {
					$output = "Which verse is this in chapter " . $chapter ."? ";
				}
				else {
					$output = "Which chapter and verse is this? ";
					$chapter = rand( 1, count( $book->chapters ) );
				}

				$verse = rand( 1, count( $book->chapters[ $chapter - 1 ] ) );
				$output .= $book->chapters[ $chapter - 1 ][ $verse - 1 ];

				$response->addOutput( $output );
				$response->withCard( $output );

				$state->mode = $mode;
				$state->book = $book;
				$state->chapter = $chapter;
				$state->verse = $verse;
			break;
		}
	}

	return $response;
}

/**
 * Output a chapter of the currently selected book.
 *
 * @param Response $response
 * @return Response
 */
function read_to_me( $response ) {
	global $state;

	$book = json_decode( file_get_contents( "data/" . $state->book . ".json" ) );

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