import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export interface PaletteCommand {
	id: string;
	label: string;
	hint?: string;
	run: () => void;
}

interface CommandPaletteProps {
	commands: PaletteCommand[];
	onClose: () => void;
}

export default function CommandPalette( { commands, onClose }: CommandPaletteProps ) {
	const [ query, setQuery ] = useState( '' );
	const [ activeIdx, setActiveIdx ] = useState( 0 );
	const inputRef = useRef<HTMLInputElement>( null );

	useEffect( () => {
		inputRef.current?.focus();
	}, [] );

	const filtered = useMemo( () => {
		const q = query.trim().toLowerCase();
		if ( ! q ) {
			return commands;
		}
		return commands.filter(
			( c ) =>
				c.label.toLowerCase().includes( q ) ||
				( c.hint && c.hint.toLowerCase().includes( q ) )
		);
	}, [ commands, query ] );

	useEffect( () => {
		setActiveIdx( 0 );
	}, [ query ] );

	const handleKeyDown = ( e: React.KeyboardEvent ) => {
		if ( e.key === 'Escape' ) {
			e.preventDefault();
			onClose();
			return;
		}
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setActiveIdx( ( i ) => Math.min( filtered.length - 1, i + 1 ) );
			return;
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setActiveIdx( ( i ) => Math.max( 0, i - 1 ) );
			return;
		}
		if ( e.key === 'Enter' && filtered[ activeIdx ] ) {
			e.preventDefault();
			filtered[ activeIdx ].run();
			onClose();
		}
	};

	return (
		<div className="rc-backdrop rc-backdrop--palette" onClick={ onClose }>
			<div className="rc-palette" onClick={ ( e ) => e.stopPropagation() } onKeyDown={ handleKeyDown } role="dialog" aria-modal="true">
				<input
					ref={ inputRef }
					type="text"
					className="rc-palette-input"
					placeholder={ __( 'Jump to entity, audit lens, bulk edit, density, or export…', 'rowcraft' ) }
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
				/>
				<div className="rc-palette-list">
					{ filtered.length === 0 ? (
						<div style={ { padding: '16px', textAlign: 'center', fontSize: '12.5px', color: 'var(--rc-ink-muted)' } }>
							{ __( 'No matching commands.', 'rowcraft' ) }
						</div>
					) : (
						filtered.map( ( cmd, idx ) => (
							<button
								key={ cmd.id }
								type="button"
								className={ `rc-palette-item ${ idx === activeIdx ? 'is-active' : '' }` }
								onClick={ () => {
									cmd.run();
									onClose();
								} }
							>
								<span>{ cmd.label }</span>
								{ cmd.hint && <span className="rc-kbd">{ cmd.hint }</span> }
							</button>
						) )
					) }
				</div>
			</div>
		</div>
	);
}
