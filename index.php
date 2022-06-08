<?php

error_log( "Received a request." );

require "./config.php";
require "./lib/amazon-alexa-php/src/autoload.php";
require "./lib/state.php";

ob_start();

$raw_request = file_get_contents("php://input");

error_log( "INPUT: " . $raw_request );

try {
	$alexa = new \Alexa\Request\Request( $raw_request, APPLICATION_ID );

	// Generate the right type of Request object
	$request = $alexa->fromData();

	$response = new \Alexa\Response\Response;
	
	// By default, always end the session unless there's a reason not to.
	$response->shouldEndSession = true;

	if ( 'LaunchRequest' === $request->data['request']['type'] ) {
		// Just opening the skill ("Open Activity Book") responds with an activity.
		handleIntent( $request, $response, 'Quizzing' );
	}
	else {
		handleIntent( $request, $response, $request->intentName );
	}

	// A quirk of the library -- you need to call respond() to set up the final internal data for the response, but this has no output.
	$response->respond();

	$json = json_encode( $response->render() );
	
	echo $json;
	
	error_log( "OUTPUT: " . $json );
} catch ( Exception $e ) {
	error_log( var_export( $e, true ) );
	
	header( "HTTP/1.1 400 Bad Request" );
	exit;
}

/** 
 * Given an intent, handle all processing and response generation.
 * This is split up because one intent can lead into another; for example,
 * moderating a comment immediately launches the next step of the NewComments
 * intent.
 *
 * @param object $request The Request.
 * @param object $response The Response.
 * @param string $intent The intent to handle, regardless of $request->intentName
 */
function handleIntent( &$request, &$response, $intent ) {
	$user_id = $request->data['session']['user']['userId'];
	$state = get_state( $user_id );

	if ( ! $request->session->new ) {
		switch ( $intent ) {
			case 'AMAZON.StopIntent':
			case 'AMAZON.CancelIntent':
				return;
			break;
		}
	}
	
	switch ( $intent ) {
		case 'QuizMe':
			$book = $request->getSlot( 'Book' );
			$chapter = $request->getSlot( 'Chapter' );
			
			$response = quiz_me( $response, $state, "inwhich", $book, $chapter );
			
			save_state( $user_id, $state );
			$response->shouldEndSession = false;
		break;
		case 'QuizMeChapter':
			$book = $state->book;
		
			$chapter = $request->getSlot( 'Chapter' );
			
			$response = quiz_me( $response, $state, "inwhich", $book, $chapter );
			
			save_state( $user_id, $state );
			$response->shouldEndSession = false;
		break;
		case 'VerseResponse':
			$response->addOutput( "Verse Response: " . $request->getSlot( 'Verse' ) );
			$response->shouldEndSession = false;
		break;
		case 'ChapterResponse':
			$response->addOutput( "Chapter Response: " . $request->getSlot( 'Chapter' ) );
			$response->shouldEndSession = false;
		break;
		case 'ChapterAndVerseResponse':
			$response->addOutput( "Chapter and Verse Response: " . $request->getSlot( 'Chapter' ) . " verse " . $request->getSlot( 'Verse' ) );
			$response->shouldEndSession = false;
		break;
		case 'FillInTheBlankResponse':
			$response->addOutput( "You filled in the blank: " . $request->getSlot( "Answer" ) );
			$response->shouldEndSession = false;
		break;
		case 'Quizzing':
		case 'AMAZON.FallbackIntent':
		case 'AMAZON.HelpIntent':
			$response->addOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );
		
			$response->addCardTitle( "Using Bible Quizzing" );
			$response->addCardOutput( "Bible Quizzing helps you learn books of the Bible. Just say 'Quiz Me on the book of Acts' or 'I want to learn Acts Chapter 2.'" );
			
			$state->last_response = $response;
			save_state( $user_id, $state );
			
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
				save_state( $user_id, $state );
				$response->output = $state->last_response->output;
			}
		break;
		case 'AMAZON.YesIntent':
			$response->addOutput( "You said yes." );
		break;
		case 'AMAZON.NoIntent':
			$response->addOutput( "You said no." );
			$response->shouldEndSession = true;
		break;
		default:
			$response->addOutput( "I don't know which intent this is: " . $intent );
			error_log( "Couldn't handle " . $intent );
		break;
	}
}

$output = ob_get_clean();

header( 'Content-Type: application/json' );
echo $output;

exit;

function quiz_me( $response, &$state, $mode, $book, $chapter = null ) {
	$book = strtolower( $book );
	
	if ( file_exists( "data/" . $book . ".json" ) ) {
		$book = json_decode( file_get_contents( "data/" . $book . ".json" ) );
		
		switch( $mode ) {
			case "inwhich":
				if ( $chapter ) {
					$output = "Which verse is this in chapter " . $chapter ."?\n\n";
				}
				else {
					$output = "Which chapter and verse is this?\n\n";
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