/**
 * LivePreview — SSR preview component for Universal Enterprise CPT blocks.
 *
 * Fetches rendered HTML from the server via the /enterprise-cpt/v1/render-block
 * endpoint, debounced 500ms after attribute changes.
 */

import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export default function LivePreview({ blockName, attributes }) {
    const [html, setHtml] = useState('');
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const timerRef = useRef(null);
    const controllerRef = useRef(null);

    const fetchPreview = useCallback(() => {
        // Abort any in-flight request.
        if (controllerRef.current) {
            controllerRef.current.abort();
        }

        const abortController =
            typeof AbortController !== 'undefined' ? new AbortController() : null;
        controllerRef.current = abortController;

        setIsLoading(true);
        setError(null);

        const path = addQueryArgs('/enterprise-cpt/v1/render-block', {
            block_name: blockName,
            attributes: JSON.stringify(attributes),
        });

        apiFetch({ path, signal: abortController?.signal })
            .then((response) => {
                if (response.error) {
                    setError(response.error);
                    setHtml('');
                } else {
                    setHtml(response.html || '');
                    setError(null);
                }
            })
            .catch((err) => {
                if (err?.name === 'AbortError') return;
                if (err?.data?.status === 404 && err?.message) {
                    setError(err.message);
                } else if (err?.message) {
                    setError(err.message);
                }
                setHtml('');
            })
            .finally(() => {
                setIsLoading(false);
            });
    }, [blockName, JSON.stringify(attributes)]);

    // Debounce the fetch by 500ms after attributes change.
    useEffect(() => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }

        timerRef.current = setTimeout(fetchPreview, 500);

        return () => {
            clearTimeout(timerRef.current);
            if (controllerRef.current) {
                controllerRef.current.abort();
            }
        };
    }, [fetchPreview]);

    if (isLoading) {
        return (
            <div
                className="enterprise-cpt-live-preview enterprise-cpt-live-preview--loading"
                style={{
                    position: 'relative',
                    minHeight: 60,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    opacity: 0.6,
                }}
            >
                <Spinner />
            </div>
        );
    }

    if (error) {
        return (
            <div
                className="enterprise-cpt-live-preview enterprise-cpt-live-preview--error"
                style={{
                    padding: 12,
                    background: '#fcf0f0',
                    border: '1px solid #d63638',
                    borderRadius: 2,
                    fontSize: 13,
                    color: '#8a1116',
                }}
            >
                {error}
            </div>
        );
    }

    return (
        <div
            className="enterprise-cpt-live-preview"
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
