export function double( value: number ): number {
	return value * 2;
}

export const broken: number = double( 'two' );
