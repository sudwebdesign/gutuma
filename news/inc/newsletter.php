<?php
/************************************************************************
 * @project Gutuma Newsletter Managment
 * @author Rowan Seymour
 * @copyright This source is distributed under the GPL
 * @file The newsletter class
 * @modifications Cyril Maguire
 */
 /* Gutama plugin package
 * @version 1.6
 * @date	01/10/2013
 * @author	Cyril MAGUIRE
*/
 
define('FILE_MARKER', "<?php die(); ?>\n");
define('MESSAGE_FILE', 'msg.php');
define('RECIPIENTS_FILE', 'recips.php');
define('LOCK_FILE', "send.lock");
define('ERROR_EXTRA', t('Check permissions for directory <code>%</code>',array(GUTUMA_TEMP_DIR)));

/**
 * The newsletter class
 */
class gu_newsletter
{
	private $id;
	private $recipients;
	private $subject;
	private $html;
	private $text;
	private $send_progress;
	private $lock;
	
	/**
	 * Constructor - creates a new empty newsletter
	 */
	public function __construct()
	{
		$this->id = time();
		$this->recipients = '';
		$this->subject = '';
		$this->html = '';
		$this->text = '';
		$this->send_progress = NULL;
	}
	
	/**
	 * Gets the ID
	 * @return int The ID
	 */
	public function get_id()
	{
		return $this->id;
	}
	
	/**
	 * Sets the ID
	 * @param int $id The ID
	 */
	public function set_id($id)
	{
		$this->id = (int)$id;
	}
	
	/**
	 * Gets the recipient list
	 * @return string The recipient list
	 */
	public function get_recipients()
	{
		return $this->recipients;
	}
	
	/**
	 * Sets the recipient list
	 * @param string $recipients The recipient list
	 */
	public function set_recipients($recipients)
	{
		$this->recipients = $recipients;
	}	
	
	/**
	 * Gets the subject
	 * @return string The subject
	 */
	public function get_subject()
	{
		return $this->subject;
	}
	
	/**
	 * Sets the subject
	 * @param string $subject The subject
	 */
	public function set_subject($subject)
	{
		$this->subject = $subject;
	}
	
	/**
	 * Gets the html part of the content
	 * @return string The html
	 */
	public function get_html()
	{
		return $this->html;
	}
	
	/**
	 * Sets the html part of the content
	 * @param string $subject The html
	 */
	public function set_html($html)
	{
		$this->html = $html;
	}
	
	/**
	 * Gets the text part of the content
	 * @return string The text
	 */
	public function get_text()
	{
		return $this->text;
	}
	
	/**
	 * Sets the text part of the content
	 * @param string $subject The text
	 */
	public function set_text($text)
	{
		$this->text = $text;
	}
	
	/**
	 * Generates the text part of the content automatically from the html part
	 */
	public function generate_text()
	{
		$this->text = html_to_text($this->html);	
	}
	
	/**
	 * Gets the sending state
	 * @return bool TRUE if this newsletter is being sent, else FALSE
	 */
	public function is_sending()
	{
		return isset($this->send_progress);
	}
	
	/**
	 * Gets the sending progress
	 * @return bool TRUE if this newsletter is being sent, else FALSE
	 */
	public function get_send_progress()
	{
		return $this->send_progress;
	}	
	
	/**
	 * Gets the unique folder associated with this newsletter
	 * @return string The temp folder path
	 */
	public function get_dir()
	{
		return realpath(GUTUMA_TEMP_DIR).'/'.$this->id;
	}
	
	/**
	 * Saves this newsletter
	 * @return bool TRUE if operation was successful, else FALSE
	 */
	public function save()
	{
		if (gu_is_demo())
			return gu_error(t('Newsletters cannot be saved or sent in demo mode'));
	
		// Create newsletter's temp directory if it doesn't already exist
		$dir = $this->get_dir();
		if (!file_exists($dir)) {
			mkdir($dir);
			mkdir($dir.'/attachments');	
		}
			
		// Save message file
		$fh = @fopen($dir.'/'.MESSAGE_FILE, 'w');
		if ($fh == FALSE)
			return gu_error(t('Unable to save newsletter draft'), ERROR_EXTRA);
			
		fwrite($fh, FILE_MARKER);
		fwrite($fh, $this->recipients."\n");
		fwrite($fh, $this->subject."\n");
		fwrite($fh, $this->html."\n");
		fwrite($fh, FILE_MARKER);
		fwrite($fh, $this->text."\n");
		fclose($fh);
		
		// Create lock file
		file_put_contents($dir.'/'.LOCK_FILE, FILE_MARKER);
		
		return TRUE;
	}
	
	/**
	 * Acquire a lock on this newsletter - i.e., blocks if another process has aquired it
	 */	
	private function acquire_lock()
	{
		gu_debug(t('Locking recipient file (%)',array($this->id)));
		
		$this->lock = @fopen($this->get_dir().'/'.LOCK_FILE, 'w');
		if (!$this->lock || !flock($this->lock, LOCK_EX))
			return gu_error(t('Unable to lock newsletter'));			
	}
	
	/**
	 * Release a lock on this newsletter
	 */
	private function release_lock()
	{
		gu_debug(t('Unlocking recipient file (%)',array($this->id)));
		
		flock($this->lock, LOCK_UN);
		fclose($this->lock);	
	}
	
	/**
	 * Prepares newsletter for sending
	 * @return TRUE if operation was successful, else FALSE
	 */
	public function send_prepare()
	{	
		// Save message to ensure message directory is created
		if (!$this->save())
			return FALSE;
			
		// Parse recipient list into addresses and list names
		$addresses = $this->parse_recipients();
		$num_addresses = count($addresses);
		
		$this->acquire_lock();
		$dir = $this->get_dir();
		
		// Save address list
		if (!file_exists($dir.'/'.RECIPIENTS_FILE)) {	
			$fh = @fopen($dir.'/'.RECIPIENTS_FILE, 'w');
			if ($fh == FALSE)
				return gu_error(t('Unable to save newsletter recipient list'), ERROR_EXTRA);
				
			$this->send_progress = array($num_addresses, $num_addresses);
			
			fwrite($fh, FILE_MARKER);
			fwrite($fh, $this->send_progress[0].'|'.$this->send_progress[1]."\n");
			foreach (array_keys($addresses) as $addr)
				fwrite($fh, $addr.'|'.$addresses[$addr]."\n");
			fclose($fh);
		}
		
		$this->release_lock();

		return TRUE;
	}
	
	/**
	 * Newsletters often can't be sent to all recipients in one batch, so this function
	 * picks up where it left off last, and sends as much as permitted by the batch settings.
	 * @param gu_mailer $mailer The mailer to use to send
	 * @param int $init_start_time If this isn't the first call to send_batch in this script execution
	 *   then this should be the start time of the first call, else NULL
	 * @return TRUE if operation was successful, else FALSE
	 */
	public function send_batch(gu_mailer $mailer, $init_start_time = NULL)
	{
		$this->acquire_lock();
		
		$dir = $this->get_dir();
		
		// Newsletter may have been deleted by the process that blocked this process, or may not be ready for sending
		if (!file_exists($dir.'/'.RECIPIENTS_FILE)) {
			$this->release_lock();
			return TRUE;
		}
		
		// Open recipient list file			
		$fh = @fopen($dir.'/'.RECIPIENTS_FILE, 'r+');
		if ($fh == FALSE)
			return gu_error(t('Unable to open newsletter recipient file'), ERROR_EXTRA);
		if (!flock($fh, LOCK_EX))
			return gu_error(t('Unable to lock newsletter recipient list'), ERROR_EXTRA);					
				
		fgets($fh); // Read file marker
		$header = explode('|', fgets($fh)); // Read header
		$remaining = $header[0];
		$total = $header[1];
		
		// Start the timer - use the passed start time value if there was one
		$start_time = isset($init_start_time) ? $init_start_time : time();
				
		// Collect failed recipients
		$failed_recipients = array();
		$total_sent = 0;
		
		// Start sending to recipients		
		while (!feof($fh)) {
			$line = trim(fgets($fh));
			if (strlen($line) == 0)
				break;
			$tokens = explode('|', $line);
			$address = $tokens[0];
			$list = $tokens[1];
			
			$res = $mailer->send_newsletter($address, $this, $list);
			if ($res === FALSE)
				return FALSE;
			elseif ($res === -1)
				$failed_recipients[] = $address.($list != '' ? (' ('.$list.')') : '');
			$total_sent++;
			
			if (((time() - $start_time) > (int)gu_config::get('batch_time_limit')) || ($total_sent >= gu_config::get('batch_max_size')))
				break;
		}
		
		// Read remaining recipients
		$remaining_recipients = array();
		while (!feof($fh)) {
			$line = trim(fgets($fh));
			if (strlen($line) > 0)
				$remaining_recipients[] = explode('|', $line);
		}
		
		// Update recipient list file
		fseek($fh, 0);
		ftruncate($fh, 0);
		fwrite($fh, FILE_MARKER);
		fwrite($fh, count($remaining_recipients).'|'.$total);
		foreach ($remaining_recipients as $recip)
			fwrite($fh, implode('|', $recip)."\n");
		fclose($fh);
		
		if (count($remaining_recipients) == 0) {
			// Delete recipients file so when we unlock, waiting processes will detect its gone and not try sending
			@unlink($dir.'/'.RECIPIENTS_FILE);
			
			// Wakeup waiting processes
			$this->release_lock();		
		
			if (!$this->delete())
				return FALSE;
		}
		else
			$this->release_lock();				
		
		if (count($failed_recipients) > 0) {
			$extra = t('Unable to deliver to:<br /><br />').implode('<br />', $failed_recipients);
			return gu_error(t('Message could not be sent to all recipients'), $extra);
		}	
			
		return TRUE;
	}
	
	/**
	 * Stores the specified file in this newsletter's directory as an attachment
	 * @param string $path The path of file to add as an attachment
	 * @param string $filename The name of the file
	 * @return bool TRUE if operation was successful, else FALSE
	 */
	public function store_attachment($path, $filename)
	{
		// Save message to ensure message directory is created
		if (!$this->save())
			return FALSE;
			
		$dest_path = $this->get_dir().'/attachments/'.$filename;
		gu_debug(t('Storing attachment ').$dest_path);
	
		// Move uploaded file to the newsletter's temp directory
		if (!@move_uploaded_file($path, $dest_path))
			return gu_error(t('Unable to save uploaded file. Check permissions for directory <code>%</code>',array(GUTUMA_TEMP_DIR)));

		return TRUE;
	}

	/**
	 * Deletes the specified file from this newsletter's directory
	 * @param string $filename The name of the file to delete
	 * @return bool TRUE if operation was successful, else FALSE
	 */
	public function delete_attachment($filename)
	{	
		// Delete file from newsletter's temp directory
		if (!@unlink($this->get_dir().'/attachments/'.$filename))
			return gu_error(t('Unable to delete uploaded file. Check permissions for directory <code>%</code>',array(GUTUMA_TEMP_DIR)));
			
		// Save the message
		if (!$this->save())
			return FALSE;

		return TRUE;
	}
	
	/**
	 * Gets the attachments stored for this newsletter
	 * @return array An array of file paths
	 */
	public function get_attachments()
	{
		$dir = $this->get_dir();
		if (!file_exists($dir))
			return array();
			
		$files = array();
		if ($dh = @opendir($dir.'/attachments')) {
			while (($file = readdir($dh)) !== FALSE) {
				if (!is_dir($file)) {
					$path = $dir.'/attachments/'.$file;
					$files[] = array('name' => $file, 'path' => $path, 'size' => filesize($path));
				}
			}
			closedir($dh);
		}
		return $files;
	}
	
	/**
	 * Parses this newsletter's recipient list into an array of addresss and list names
	 * @return array The array of email addresses and list names
	 */
	public function parse_recipients()
	{
		$list_names = array();
		$addresses = array();		
		
		$items = explode(';', $this->recipients);
		foreach ($items as $r) {
			$recip = trim($r);
			if (strlen($recip) == 0)
				continue;
			// If token contains a @ then its an email address, otherwise its list
			elseif (strpos($recip, '@') === FALSE)
				$list_names[] = $recip;
			else
				$addresses[$recip] = '';
		}
		
		// Add addresses from each list, in reverse order, so that duplicates for addresses on more than one list, come from the first occuring lists
		for ($l = (count($list_names) - 1); $l >= 0; $l--) {
			if ($list = gu_list::get_by_name($list_names[$l], TRUE)) {
				foreach ($list->get_addresses() as $address)
					$addresses[$address] = $list->get_name();
			}
			else
				return gu_error(t('Unrecognized list name <i>%</i>',array($list_names[$l])));
		}
		
		// If admin wants a copy, add the admin address as well
		if (gu_config::get('msg_admin_copy'))
			$addresses[gu_config::get('admin_email')] = '';
		
		return $addresses;
	}
	
	/**
	 * Cleans up any resources used by this newsletter - i.e. deletes file attachments from temp storage
	 * @return bool TRUE if operation was successful, else FALSE
	 */
	public function delete()
	{
		gu_debug(t('Deleting newsletter files (%)',array($this->id)));
		
		$dir = $this->get_dir();		
		if (!file_exists($dir))
			return TRUE;
			
		// Delete individual attachments to ensure directory is empty
		foreach ($this->get_attachments() as $attachment) {
			if (!$this->delete_attachment($attachment['name']))
				return gu_error(t('Unable to delete message attachment'), ERROR_EXTRA);
		}
		
		// Delete the newsletter files
		$res1 = @rmdir($dir.'/attachments');
		$res2 = @unlink($dir.'/'.MESSAGE_FILE);
		$res3 = @unlink($dir.'/'.LOCK_FILE);
		$res4 = !file_exists($dir.'/'.RECIPIENTS_FILE) || @unlink($dir.'/'.RECIPIENTS_FILE);
		$res5 = @rmdir($dir);	
		if (!($res1 && $res2 && $res3 && $res4 && $res5))
			return gu_error(t('Some newsletter files could not be deleted'), ERROR_EXTRA);
			
		$this->send_progress = NULL;			
		
		return TRUE;
	}
	
	/**
	 * Gets a newsletter
	 * @param int $id The id of the newsletter to retrieve
	 * @return mixed The newsletter if it was loaded successfully, else FALSE if an error occured
	 */
	public static function get($id)
	{			
		// Open message file
		$h = @fopen(realpath(GUTUMA_TEMP_DIR.'/'.$id.'/'.MESSAGE_FILE), 'r');
		if ($h == FALSE)
			return gu_error(t("Unable to open message file"));
	
		fgets($h); // Discard first line
		$newsletter = new gu_newsletter();
		$newsletter->id = $id;
		$newsletter->recipients = fgets($h);
		$newsletter->subject = fgets($h);		
	
		// Read message HTML up to marker
		while (!feof($h)) {
			$line = fgets($h);
			if ($line == FILE_MARKER)
				break;
			else
				$newsletter->html .= $line;
		}
		
		// Read message TEXT as rest of file
		while (!feof($h))
			$newsletter->text .= fgets($h);
		fclose($h);
		
		// Check for recips file which means its being sent
		$recip_file = GUTUMA_TEMP_DIR.'/'.$id.'/'.RECIPIENTS_FILE;
		if (file_exists($recip_file)) {		
			// Open list file
			$rh = @fopen(realpath($recip_file), 'r');
			if ($rh == FALSE)
				return gu_error(t("Unable to read newsletter recipient file"));
				
			fgets($rh); // Read file marker line
			
			$header = explode("|", fgets($rh));
			$newsletter->send_progress = array($header[0], $header[1]);
			fclose($rh);
		}
		
		return $newsletter;
	}
	
	/**
	 * Gets all the newsletters
	 * @return array The newsletters
	 */
	public static function get_all()
	{
		$newsletters = array();
		
		if ($dh = @opendir(realpath(GUTUMA_TEMP_DIR))) {
			while (($file = readdir($dh)) !== FALSE) {
				if ($file == '.' || $file == '..' || $file[0] == '.')
					continue;
					
				if (($newsletter = self::get($file)) !== FALSE)				
					$newsletters[] = $newsletter;			
			}
		}
		else
			return gu_error(t('Unable to open newsletter folder'), ERROR_EXTRA);
					
		return $newsletters;
	}
	
	/**
	 * Gets all the newsletters, organized into a mailbox
	 * @return array The newsletters as a mailbox with top level indexes of drafts and outbox
	 */
	public static function get_mailbox()
	{
		$mailbox = array('drafts' => array(), 'outbox' => array());
		if (($newsletters = self::get_all()) === FALSE)
			return FALSE;
		
		foreach ($newsletters as $newsletter) {
			if ($newsletter->is_sending())
				$mailbox['outbox'][] = $newsletter;
			else
				$mailbox['drafts'][] = $newsletter;			
		}
		
		return $mailbox;
	}
}
