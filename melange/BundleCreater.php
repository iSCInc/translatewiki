<?php
class BundleCreater {
	protected $conf;
	protected $dir;

	public function __construct( $conf, $dir ) {
		$this->conf = $conf;
		$this->dir = $dir;
	}

	public function reset() {
		exec( 'rm -rf extensions/ mediawiki/ db/' );
	}

	public function make_release() {
		$this->clone_mediawiki();
		$this->update_mediawiki();
		$this->clone_extensions();
		$this->update_extensions();
		$this->checkout_release();
		// Broken
		//$this->run_tests();
		$this->prepare_notes();
		$this->create_tag();
		$this->push_tag();
		$this->create_archive();
		$this->prepare_announcement();
		$this->final_steps();
	}

	public function clone_mediawiki() {
		chdir( $this->dir );
		if ( file_exists( 'mediawiki' ) ) {
			return;
		}

		$repo = escapeshellarg( $this->conf['common']['mediawikirepo'] );
		$target = escapeshellarg( $mediawiki );
		exec( "git clone $repo $target" );
	}

	public function install_mediawiki() {
		chdir( $this->dir );

		exec( 'rm -rf db/' );
		mkdir( 'db' );

		chdir( 'mediawiki' );

		unlink( 'LocalSettings.php' );
		$install = escapeshellarg( "maintenance/install.php" );
		$db = escapeshellarg( realpath( getcwd() . '/../db/' ) );

		$params = '';
		foreach ( $this->conf['install'] as $param => $val ) {
			$params .= ' ' . escapeshellarg( "--$param" ) . '=' . escapeshellarg( $val );
		}

		exec( "php $install $params --dbpath $db melange WikiSysop" );

		$handle = fopen( 'LocalSettings.php', 'a' );
		fwrite( $handle, <<<PHP
require_once( "\$IP/../mwtestconf.php" );
PHP
		);
	}

	public function update_mediawiki() {
		chdir( $this->dir );
		chdir( "mediawiki" );

		exec( "git fetch --all --quiet" );
		exec( "php maintenance/update.php --quick --quiet" );
	}

	public function clone_extensions() {
		chdir( $this->dir );

		if ( !file_exists( "extensions" ) ) {
			mkdir( "extensions/" );
		}

		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			$target = "extensions/$ext";
			if ( !file_exists( "$target" ) ) {
				$repo = escapeshellarg( $this->conf['common']['extensionrepo'] . $ext . ".git" );
				$target = escapeshellarg( $target );
				exec( "git clone $repo $target" );
			}
		}
	}

	public function update_extensions() {
		chdir( $this->dir );
		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			$target = "extensions/$ext";
			chdir( $target );
			exec( "git fetch --all --quiet" );
		}
	}

	public function checkout_release() {
		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			chdir( $this->dir );
			chdir( "extensions/$ext" );

			$checkout = escapeshellarg( $checkout );
			exec( "git checkout $checkout --quiet" );
		}
	}

	public function run_tests() {
		chdir( $this->dir );
		chdir( "mediawiki" );

		$branches = explode( ' ', $this->conf['common']['branches'] );
		foreach ( $branches as $branch ) {
			exec( "git checkout $branch --quiet" );
		}
	}

	public function prepare_notes() {
		$version = $this->conf['common']['releasever'];

		$old = 'HEAD~100';
		if ( isset( $this->conf['releasever-prev'] ) ) {
			$old = $this->conf['releasever-prev'];
		}

		if ( !file_exists( 'notes' ) ) {
			mkdir( 'notes' );
		}

		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			chdir( $this->dir );
			chdir( "extensions/$ext" );

			$diff = escapeshellarg( "$old..$checkout" );
			$target = "{$this->dir}/notes/$version-$ext";
			$file = escapeshellarg( $target );

			exec( "git shortlog $diff > $file" );
			exec( "git log $diff >> $file" );
			exec( "git diff $diff >> $file" );
			$content = file_get_contents( $target );
			$header = <<<TEXT
# Please write release notes for extension $ext
# Can also be left empty, but keep the #--- lines
#---

=== Highlights ===
...

=== Noteworthy changes ===
* ...

#---
# Below are logs and changes from previous release
# (or latest 100 changes if not available) for help
TEXT;


			$content = $header . $content;
			file_put_contents( $target, $content );
			passthru( "\$EDITOR $file" );
		}

	}

	public function create_tag() {
		$name = $this->conf['common']['bundlename'];
		$version = $this->conf['common']['releasever'];
		$branch = str_replace( '$1', $version, $this->conf['common']['branchname'] );
		$tag = str_replace( '$1', $version, $this->conf['common']['tagname'] );
		$date = date( 'Y-m-d' );
		$extra = str_replace( '$1', $version, $this->conf['common']['versionextra'] );

		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			chdir( "{$this->dir}/extensions/$ext" );
			$cBranch = escapeshellarg( $branch );
			$cCheckout = escapeshellarg( $checkout );
			exec( "git checkout -b $branch $cCheckout --force --quiet" );
			exec( "git reset --hard $cCheckout --quiet" );
			exec( "git rm .gitreview --quiet --ignore-unmatch" );

			$notefile = $this->dir . "/notes/$version-$ext";
			$contents = file_get_contents( $notefile );
			preg_match( '/^#---$(.*)^#---$/msU', $contents, $matches );
			$notes = trim( $matches[1] );
			$notes = "== $ext $version ==\nReleased at $date.\n\n$notes\n";

			$relnotefile = "{$this->dir}/extensions/$ext/RELEASE-NOTES";
			file_put_contents( $relnotefile, $notes );

			// Patch version
			$setupfile = "{$this->dir}/extensions/$ext/$ext.php";
			$contents = file_get_contents( $setupfile );
			$contents = preg_replace_callback( "/(^\s*'version'\s*=>\s*)(.*)/m", function ( $matches ) use ( $extra ) {
				return "{$matches[1]}'$extra ' . {$matches[2]}";
			}, $contents );

			file_put_contents( $setupfile, $contents );

			$msg = escapeshellarg( "$name $version" );

			exec( 'git add .' );
			exec( "git commit -v -m $msg --quiet" );

			$cTag = escapeshellarg( $tag );
			exec( "git tag -a $tag -m $msg" );
		}
	}

	public function push_tag() {
		$tag = str_replace( '$1', $version, $this->conf['common']['tagname'] );

		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			chdir( "{$this->dir}/extensions/$ext" );
			$cTag = escapeshellarg( $tag );
			exec( "git push origin $cTag );
		}
	}

	protected function getReleaseFileName() {
		$version = $this->conf['common']['releasever'];
		$name = $this->conf['common']['bundlename'];
		$filename = preg_replace_callback( '/\s+(.)/', function ( $matches ) {
			return strtoupper( $matches[1] );
		}, $name );
		return "$filename-$version.tar.bz2";
	}

	public function create_archive() {
		chdir( $this->dir );

		$hasher = $this->conf['common']['hasher'];

		if ( !file_exists( 'releases' ) ) {
			mkdir( 'releases' );
		}

		$filename = $this->getReleaseFileName();

		$tarname = escapeshellarg( "releases/$filename" );
		$hashname = escapeshellarg( "$filename.$hasher" );
		$hashcommand = escapeshellarg( $hasher );
		exec( "tar cjf $tarname --exclude-vcs extensions" );
		chdir( 'releases' );
		exec( "$hasher $filename > $hashname" );
	}

	public function prepare_announcement() {
		chdir( $this->dir );

		$version = $this->conf['common']['releasever'];
		$name = $this->conf['common']['bundlename'];
		$url = $this->conf['common']['downloadurl'];
		$hasher = $this->conf['common']['hasher'];

		$filename = $this->getReleaseFileName();
		$contents = file_get_contents( "releases/$filename.$hasher" );
		list( $hash, /*unused*/ ) = explode( ' ', $contents, 2 );

		$parts = array();
		$parts[] = "I would like to announce the release of $name $version";
		$parts[] = "$url/$filename\n$hasher: $hash";
		$parts[] = <<<WIKITEXT
Quick links:
* Installation instructions are at https://www.mediawiki.org/wiki/MLEB
* Announcements of new releases will be posted to a mailing list:
  https://lists.wikimedia.org/mailman/listinfo/mediawiki-i18n
* Report bugs to https://bugzilla.wikimedia.org
* Talk with us at #mediawiki-i18n @ freenode
WIKITEXT;
		$parts[] = 'Release notes for each extension are below.';
		$parts[] = '    YOUR NAME';

		if ( !file_exists( 'notes' ) ) {
			mkdir( 'notes' );
		}

		foreach ( $this->conf['extensions'] as $ext => $checkout ) {
			$notefile = $this->dir . "/notes/$version-$ext";
			$contents = file_get_contents( $notefile );
			preg_match( '/^#---$(.*)^#---$/msU', $contents, $matches );
			if ( !isset( $matches[1] ) ) {
				echo "Could not parse notes for extension $ext\n";
				continue;
			}
			$notes = trim( $matches[1] );
			$parts[] = "== $ext ==\n$notes";
		}

		$announcement = $this->dir . "/notes/$version";

		file_put_contents( $announcement, implode( "\n\n", $parts ) );
		passthru( "\$EDITOR $announcement" );

		echo "Please complete the following steps to finish the release process:\n";
		echo "* Move files $filename* so that they can be downloaded from $url.\n";
		echo "* Send the release announcement from $announcement to mediawiki-i18n.\n";
		echo "* Consider also blogging/twitter etc.\n";
	}

}