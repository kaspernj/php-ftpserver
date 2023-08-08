<?
	unset($serverports);
	
	$serverports_alsta = 32400;
	$serverports_alend = 32500;
	$serverports_count = $serverports_alsta;
	
	$myip = "192.168.0.2";
	
	function ftp_getdatabypid($pid){
		global $clientsdata;
		
		foreach($clientsdata AS $value){
			if ($value[current_pid] == $pid){
				return $value;
			}
		}
	}
	
	function ftp_getunusedport(){
		global $serverports, $serverports_alsta, $serverports_alend, $serverports_count;
		
		if (!$serverports[$serverports_count]){
			$port = rand($serverports_alsta, $serverports_alend);
			$p2 = 100;
			$p1 = floor(($port - $p2) / 256);
			$p2 += $port - (($p1 * 256) + $p2);
			
			$serverports[$serverports_count] = true;
			$serverports_count++;
			
			$return[port] = $port;
			$return[p1] = $p1;
			$return[p2] = $p2;
			
			return $return;
		}else{
			$serverports_count++;
			return ftp_getunusedport();
		}
	}
	
	function ftp_validate($fp, $command, $pid){
		global $clientsdata, $myip, $serverports;
		
		$cdata = ftp_getdatabypid($pid);
		echo "Recv: " . $command;
		
		if (substr($command, 0, 5) == "USER "){
			$user = trim(substr($command, 5));
			
			echo "Han prøver at logge ind som en bruger (" . $user . ").\n";
			
			$clientsdata[$user][current_pid] = $pid;
			$clientsdata[$user][nick] = $user;
			$clientsdata[$user][status] = "login_needpass";
			
			ssend($fp, "331 Password required for " . $user . "\r\n");
		}elseif(substr($command, 0, 5) == "PASS "){
			if ($cdata[status] == "login_needpass"){
				if (trim(substr($command, 5)) == "apacer64"){
					ssend($fp, "230 User " . $cdata[nick] . " logged in.\r\n");
					
					$clientsdata[$cdata[nick]][status] = "loggedin";
					$clientsdata[$cdata[nick]][folder] = "/home/shared";
					$clientsdata[$cdata[nick]][folder_current] = "/";
				}
			}else{
				ssend($fp, "0 You dont need to send the password two times? You are already logged in?\r\n");
			}
		}elseif(substr($command, 0, 4) == "FEAT"){
			ssend($fp, "211-Features:\r\n");
			ssend($fp, "211 End\r\n");
		}elseif(substr($command, 0, 4) == "SYST"){
			ssend($fp, "215 UNIX Type: L8\r\n");
		}elseif(substr($command, 0, 3) == "PWD"){
			ssend($fp, "257 \"" . $cdata[folder_current] . "\" is current directory.\r\n");
		}elseif(substr($command, 0, 6) == "TYPE A"){
			ssend($fp, "200 Type set to A\r\n");
		}elseif(substr($command, 0, 4) == "PASV"){
			$port = ftp_getunusedport();
			$myip_ar = explode(".", $myip);
			
			$pasv_fp = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Could not create socket-object for passive transfer.");
			
			if (!socket_bind($pasv_fp, "0.0.0.0", $port[port])){
				echo "Couldt not bind socket to port " . $port[port] . " for passive transfer.";
				exit();
			}
			
			socket_listen($pasv_fp);
			socket_setopt($pasv_fp, SOL_SOCKET, SO_REUSEADDR, 1) or die("Could not set options for socket-object for passive transfer.");
			
			$clientsdata[$cdata[nick]][pasv] = true;
			$clientsdata[$cdata[nick]][pasv_port] = $port[port];
			$clientsdata[$cdata[nick]][pasv_fp] = $pasv_fp;
			
			ssend($fp, "227 Entering Passive Mode (" . $myip_ar[0] . "," . $myip_ar[1] . "," . $myip_ar[2] . "," . $myip_ar[3] . "," . $port[p1] . "," . $port[p2] . ")\r\n");
		}elseif(substr($command, 0, 4) == "LIST"){
			$filelist = "";
			
			$opendir = opendir($cdata[folder] . "/" . $cdata[folder_current]);
			while(($file = readdir($opendir)) !== false){
				$filelist .= ftp_generatefilename($cdata[folder] . "/" . $cdata[folder_current], $file);
			}
			
			if ($cdata[pasv] == true){
				ssend($fp, "150 Opening ASCII mode data connection for file list\r\n");
				
				$written = false;
				
				$fp_rm = socket_accept($cdata[pasv_fp]);
				ssend($fp_rm, $filelist);
				socket_close($cdata[pasv_fp]);
				
				ssend($fp, "226 Transfer complete.\r\n");
				
				unset($clientsdata[$cdata[nick]][pasv]);
				unset($clientsdata[$cdata[nick]][pasv_port]);
				unset($clientsdata[$cdata[nick]][pasv_fp]);
			}
		}
	}
	
	function ftp_generatefilename($folder, $file){
		$filename = $folder . "/" . $file;
		
		$fileowner = fileowner($filename);
		$filesize = filesize($filename);
		
		
		if (is_dir($filename)){
			$return .= "drwxr-x---  15 vincent  vincent      4096 Nov  3 21:31 " . $file . "\r\n";
		}else{
			$return .= "lrwxrwxrwx   1 vincent  vincent        11 Jul 12 12:16 " . $file . "\r\n";
		}
		
		return $return;
	}
	
	function ssend($socket, $msg){
		echo "   Send: " . $msg;
		return socket_write($socket, $msg, strlen($msg));
	}
	
	echo "FTP-server starting up, trying to open socket...\n";
	
	$fp = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die("Could not create socket-object.");
	socket_bind($fp, "0.0.0.0", 21) or die("Could not bind socket to port 21.");
	socket_listen($fp) or die("Could not listen on port 21.");
	socket_setopt($fp, SOL_SOCKET, SO_REUSEADDR, 1) or die("Could not set options for socket-object.");
	//socket_set_nonblock($fp);
	
	echo "Waiting for connections...\n";
	
	while($fp_fm = socket_accept($fp)){
		$pid = pcntl_fork();
		$so[$pid] = $fp_fm;
		
		if ($pid == -1){
			echo "Could not fork new objects...\n";
		}elseif($pid){
			socket_getpeername($so[$pid], $raddr, $rport);
			echo "Connection made by " . $raddr . ":" . $rport . ".\n";
			
			ssend($so[$pid], "220 Welcome\r\n");
			
			while(socket_recv($so[$pid], $receive, 1024, 0)){
				ftp_validate($so[$pid], $receive, $pid);
			}
		}
		
		$sc++;
	}
	
	socket_close($fp);
?>