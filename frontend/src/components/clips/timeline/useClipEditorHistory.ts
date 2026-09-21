"use client";

import { useCallback, useReducer } from "react";
import type { AdditionalVideoClip, CropKeyframe, Segment, SubtitleCue, TemplateLayer } from "@/lib/types";

export interface EditorSnapshot {
  segments: Segment[];
  // Extra videos appended after the main clip's own segments — see
  // AdditionalClipsPanel and RenderClipJob::renderAdditionalVideoClips().
  additionalVideoClips: AdditionalVideoClip[];
  layers: TemplateLayer[];
  // Clip-relative caption cues, seeded from the last render's transcript-derived
  // ones (or the user's own saved edits). Part of the snapshot so retiming or
  // rewording a caption undoes/redoes alongside every other timeline edit.
  captionCues: SubtitleCue[];
  // Whether the user has actually touched a caption yet. Only then does the
  // editor persist `caption_cues` — saving them unconditionally would freeze
  // captions for every clip the moment anyone pressed Save, permanently
  // opting it out of transcript regeneration.
  captionCuesDirty: boolean;
  cropMode: "smart" | "manual";
  // Single manual crop position (v1 scope — no multi-point manual pan yet, see
  // ManualCropEditor); still stored as a one-item keyframes array on save so the
  // schema already supports adding pan keyframes later with no migration.
  cropKeyframe: CropKeyframe | null;
}

interface HistoryState {
  past: EditorSnapshot[];
  present: EditorSnapshot;
  future: EditorSnapshot[];
}

type Action =
  | { type: "push"; snapshot: EditorSnapshot }
  | { type: "undo" }
  | { type: "redo" }
  | { type: "reset"; snapshot: EditorSnapshot };

function reducer(state: HistoryState, action: Action): HistoryState {
  switch (action.type) {
    case "push":
      return { past: [...state.past, state.present], present: action.snapshot, future: [] };
    case "undo": {
      if (state.past.length === 0) return state;
      const previous = state.past[state.past.length - 1];
      return { past: state.past.slice(0, -1), present: previous, future: [state.present, ...state.future] };
    }
    case "redo": {
      if (state.future.length === 0) return state;
      const next = state.future[0];
      return { past: [...state.past, state.present], present: next, future: state.future.slice(1) };
    }
    case "reset":
      return { past: [], present: action.snapshot, future: [] };
    default:
      return state;
  }
}

/**
 * Client-side, in-memory undo/redo over the clip editor's trim + layers state —
 * lost on navigation/reload by design (an editing convenience, not a persisted
 * document). Push a snapshot on each discrete edit (drag-end, blur, layer
 * add/remove), not on every intermediate drag frame.
 */
export function useClipEditorHistory(initial: EditorSnapshot) {
  const [state, dispatch] = useReducer(reducer, { past: [], present: initial, future: [] });

  return {
    current: state.present,
    canUndo: state.past.length > 0,
    canRedo: state.future.length > 0,
    push: useCallback((snapshot: EditorSnapshot) => dispatch({ type: "push", snapshot }), []),
    undo: useCallback(() => dispatch({ type: "undo" }), []),
    redo: useCallback(() => dispatch({ type: "redo" }), []),
    reset: useCallback((snapshot: EditorSnapshot) => dispatch({ type: "reset", snapshot }), []),
  };
}
