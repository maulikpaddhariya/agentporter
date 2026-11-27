<?php
// SEEDED: a file that throws while being loaded (e.g. quarantined or half-written by an update).
namespace AgentPorter\Tests\Broken;

throw new \RuntimeException( 'quarantined file' );
