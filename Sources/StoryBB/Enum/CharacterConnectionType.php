<?php

namespace StoryBB\Enum;

class CharacterConnectionType
{
	public static function from($int) {
		switch ($int)
		{
			case 0:
				return new class { public function direction() { return 'undirected'; } };
			case 1:
				return new class { public function direction() { return 'directed'; } };
			case 2:
				return new class { public function direction() { return 'mutual'; } };
		}
	}
}

/*
enum CharacterConnectionType: int
{
	case Undirected = 0;
	case Directed = 1;
	case Mutual = 2;

	public function direction(): string {
		return match($this) {
			CharacterConnectionType::Undirected => 'undirected',
			CharacterConnectionType::Directed => 'directed',
			CharacterConnectionType::Mutual => 'mutual',
		};
	}
}*/