/**
 * usePolling — run a fetch function now and on an interval.
 *
 * @param {Function} fetcher  Async function returning data.
 * @param {number}   interval Poll interval in ms (default 60000).
 * @param {Array}    deps     Dependencies that trigger an immediate refetch.
 * @return {{data: *, loading: boolean, error: *, refetch: Function}}
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';

export default function usePolling( fetcher, interval = 60000, deps = [] ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	// Keep the latest fetcher without forcing the effect to re-run each render.
	const fetcherRef = useRef( fetcher );
	fetcherRef.current = fetcher;

	const refetch = useCallback( async () => {
		setLoading( true );
		try {
			const result = await fetcherRef.current();
			setData( result );
			setError( null );
		} catch ( err ) {
			setError( err );
		} finally {
			setLoading( false );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		let cancelled = false;

		const tick = async () => {
			if ( ! cancelled ) {
				await refetch();
			}
		};

		tick();
		const id = setInterval( tick, interval );

		return () => {
			cancelled = true;
			clearInterval( id );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ interval, ...deps ] );

	return { data, loading, error, refetch };
}
