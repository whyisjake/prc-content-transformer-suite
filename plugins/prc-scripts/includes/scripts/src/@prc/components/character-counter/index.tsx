export interface CharacterCounterProps {
	current: number;
	limit: number;
}

function getCharCountColor(current: number, limit: number): string {
	if (limit <= 0) {
		return '#22C55E';
	}
	const ratio = current / limit;
	if (ratio >= 1) {
		return '#EF4444';
	}
	if (ratio >= 0.8) {
		return '#F59E0B';
	}
	return '#22C55E';
}

export default function CharacterCounter({
	current,
	limit,
}: CharacterCounterProps) {
	return (
		<span style={{ color: getCharCountColor(current, limit) }}>
			{current} / {limit}
		</span>
	);
}
