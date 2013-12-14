<?php
	/**
	 * Rewrite Wikimedia UK's events page into a more convenient .ics format
	 *
	 * This program is free software; you can redistribute it and/or modify
	 * it under the terms of the GNU General Public License as published by
	 * the Free Software Foundation; either version 2 of the License, or
	 * (at your option) any later version.
	 *
	 * This program is distributed in the hope that it will be useful,
	 * but WITHOUT ANY WARRANTY; without even the implied warranty of
	 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
	 * GNU General Public License for more details.
	 *
	 * You should have received a copy of the GNU General Public License along
	 * with this program; if not, write to the Free Software Foundation, Inc.,
	 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
	 * http://www.gnu.org/copyleft/gpl.html
	 *
	 * @author Harry Burt <http://harryburt.co.uk>
	 */

	// Get page contents
	$page = file_get_contents( 'https://wikimedia.org.uk/w/api.php?action=parse&page=Events&prop=text&disablepp&format=json' );
	$page = json_decode( $page, true );
	$page = $page['parse']['text']['*'];

	// Extract events (html-parsing, yuk)
	preg_match_all( '/\<li\>\<span class="vevent"\>(.*?)\<\/span\>\<\/li\>/', $page, $rawEvents );
	$rawEvents = $rawEvents[1];

	$events = array();
	foreach( $rawEvents as &$rawEvent ){
		// More HTML parsing
		preg_match( '/abbr class="dtstart" title="(20[1-9][0-9]-[01]?[0-9]-[0123]?[0-9])".*?span class="summary"\>(.*)\<\/span\>$/', $rawEvent, $details );
		list( , $startDate, $summary ) = $details;

		if( !$startDate ) continue;

		$endDate = false;
		if( preg_match( '/abbr class="dtend" title="(20[1-9][0-9]-[01]?[0-9]-[0123]?[0-9])"/', $rawEvent, $details ) ){
			$endDate = $details[1];
		}

		$startEpoch = strtotime( $startDate );
		$endEpoch = $endDate ? strtotime( $endDate ) : false;
		$uid = substr( md5( $startEpoch.$summary ), 0, 70 ).'@wikimedia.org.uk';

		// Extract link and put into standard format
		$url = '';
		if( preg_match( '/href="(.*?)"/', $rawEvent, $url ) ){
			$url = $url[1];
		}
		if( substr( $url, 0, 6 ) == '/wiki/' ) $url = 'https://wikimedia.org.uk' . $url;
		if( substr( $url, 0, 2 ) == '//' ) $url = 'https:' . $url;

		// Strip HTML-annotations
		$summary = preg_replace( '/\<.*?\>/', '', $summary );
		$summary = preg_replace( '/\<.*?\>/', '', $summary );
		$summary = preg_replace( '/\<.*?\>/', '', $summary );

		// Escape
		$summary = str_replace( '\\', '\\\\', $summary );
		$summary = str_replace( ',', '\,', $summary );
		$summary = str_replace( ';', '\,', $summary );

		// Fit into array
		$event = array(
			'uid' => $uid,
			'summary' => $summary,
			'start' => $startEpoch,
			'end' => $endEpoch,
			'url' => $url
		);
		$events[] = $event;
	}

	// The above could be used fairly easily for any calendar format. The below formats it
	// specifically into ICS and outputs that

	// Firstly, as described at http://www.innerjoin.org/iCalendar/all-day-events.html
	// we'll use the format reserved for birthdays, anniversaries, etc. The downside is that,
	// since birthdays only last a day, we need to duplicate things

	$newEvents = array();
	foreach( $events as $event ){
		if( !$event['end'] ) continue;
		$pointInTime = $event['start'] + (24 * 60 * 60);
		while( $pointInTime <= $event['end'] ){
			// Not strictly necessary to create $newEvent, but one might want to change
			// $event to a pointer in the future
			$newEvent = $event;
			$newEvent['start'] = $pointInTime;
			$newEvent['uid'] = substr( md5( $pointInTime.$event['summary'] ), 0, 70 ).'@wikimedia.org.uk';
			$pointInTime += (24 * 60 * 60);
			$newEvents[] = $newEvent;
		}
	}
	$events = array_merge( $events, $newEvents );

	// Set some headers
	header('Content-type: text/calendar; charset=utf-8');
	header('Content-Disposition: inline; filename="calendar.ics"');
	header("Cache-Control: no-cache, must-revalidate"); // HTTP/1.1
	header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

	// And finally splurge our output...
	$output = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//hacksw/handcal//NONSGML v1.0//EN\r\n";
	foreach( $events as $event ){
		$output .= "BEGIN:VEVENT\r\n";
		$output .= "UID:" . $event['uid'] . "\r\n";
		$output .= "DTSTAMP:" . gmdate('Ymd').'T'. gmdate('His') . "Z\r\n";
		$output .= "DTSTART;VALUE=DATE:" . date( 'Ymd', $event['start'] ) . "\r\n";
		$output .= "SUMMARY:" . $event['summary'] . "\r\n";
		if( strlen( $event['url'] ) > 0 ) $output .= "URL:" . $event['url'] . "\r\n";
		$output .= "END:VEVENT\r\n";
	}
	$output .= "END:VCALENDAR";
	echo $output;