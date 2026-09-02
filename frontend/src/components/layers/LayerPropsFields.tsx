"use client";

import { Input, Label, Select } from "@/components/ui/Input";
import type {
  AudioLayerProps,
  ImageLayerProps,
  ProgressBarLayerProps,
  RectLayerProps,
  TemplateLayer,
  TextLayerProps,
} from "@/lib/types";

type Props = { layer: TemplateLayer; onChange: (patch: Partial<TemplateLayer>) => void };

function PositionFields({ layer, onChange }: Props) {
  return (
    <div className="grid grid-cols-3 gap-3">
      <div>
        <Label>X ({Math.round((layer.x ?? 0) * 100)}%)</Label>
        <input
          type="range"
          min={0}
          max={1}
          step={0.01}
          value={layer.x ?? 0}
          onChange={(e) => onChange({ x: Number(e.target.value) })}
          className="mt-2.5 w-full accent-accent"
        />
      </div>
      <div>
        <Label>Y ({Math.round((layer.y ?? 0) * 100)}%)</Label>
        <input
          type="range"
          min={0}
          max={1}
          step={0.01}
          value={layer.y ?? 0}
          onChange={(e) => onChange({ y: Number(e.target.value) })}
          className="mt-2.5 w-full accent-accent"
        />
      </div>
      <div>
        <Label>Opacity ({Math.round((layer.opacity ?? 1) * 100)}%)</Label>
        <input
          type="range"
          min={0}
          max={1}
          step={0.05}
          value={layer.opacity ?? 1}
          onChange={(e) => onChange({ opacity: Number(e.target.value) })}
          className="mt-2.5 w-full accent-accent"
        />
      </div>
    </div>
  );
}

function StackingField({ layer, onChange }: Props) {
  // Layers always render on top of the caption UNLESS their z_index is
  // negative — see FFmpegService::renderClip()'s split into
  // $behindCaptionLayers/$aboveCaptionLayers. z_index otherwise only orders
  // layers against each other (via the move up/down arrows above), so this is
  // the only way to ask for "behind the caption" instead — needed for a
  // full-width bar/background that would otherwise cover caption text sitting
  // in the same area.
  const behindCaption = layer.z_index < 0;

  function toggle(checked: boolean) {
    const magnitude = Math.max(1, Math.abs(layer.z_index));
    onChange({ z_index: checked ? -magnitude : magnitude });
  }

  return (
    <label className="flex items-center gap-2 text-xs text-muted">
      <input
        type="checkbox"
        checked={behindCaption}
        onChange={(e) => toggle(e.target.checked)}
        className="size-4 rounded accent-accent"
      />
      Render behind captions (instead of covering them)
    </label>
  );
}

function TimingFields({ layer, onChange, clipDuration }: Props & { clipDuration?: number }) {
  const start = layer.timing?.start ?? 0;
  const end = layer.timing?.end;
  return (
    <div className="grid grid-cols-2 gap-3">
      <div>
        <Label>Show from (s)</Label>
        <Input
          type="number"
          min={0}
          step={0.1}
          value={start}
          onChange={(e) => onChange({ timing: { start: Number(e.target.value), end: end ?? null } })}
        />
      </div>
      <div>
        <Label>Show until {end == null && <span className="font-normal text-muted">(end of clip)</span>}</Label>
        <div className="flex items-center gap-1.5">
          <Input
            type="number"
            min={0}
            step={0.1}
            placeholder={clipDuration ? String(clipDuration) : "end"}
            value={end ?? ""}
            onChange={(e) => onChange({ timing: { start, end: e.target.value === "" ? null : Number(e.target.value) } })}
          />
        </div>
      </div>
    </div>
  );
}

export function LayerPropsFields({ layer, onChange }: Props) {
  function updateProps(patch: Record<string, unknown>) {
    onChange({ props: { ...(layer.props as Record<string, unknown>), ...patch } });
  }

  return (
    <>
      <PositionFields layer={layer} onChange={onChange} />
      <StackingField layer={layer} onChange={onChange} />
      <TimingFields layer={layer} onChange={onChange} />

      {layer.type === "text" && (
        <TextFields layer={layer} props={(layer.props as TextLayerProps) ?? {}} onChange={onChange} onPropsChange={updateProps} />
      )}
      {(layer.type === "image" || layer.type === "logo") && (
        <ImageFields layer={layer} props={(layer.props as ImageLayerProps) ?? {}} onChange={onChange} onPropsChange={updateProps} />
      )}
      {layer.type === "audio" && (
        <AudioFields props={(layer.props as AudioLayerProps) ?? {}} onChange={updateProps} />
      )}
      {layer.type === "progress_bar" && (
        <ProgressBarFields props={(layer.props as ProgressBarLayerProps) ?? {}} onChange={updateProps} />
      )}
      {layer.type === "rect" && (
        <RectFields layer={layer} props={(layer.props as RectLayerProps) ?? {}} onChange={onChange} onPropsChange={updateProps} />
      )}
      {layer.type === "pip_video" && (
        <p className="text-xs text-muted">
          Picture-in-picture layers aren&apos;t rendered yet in this pass — the reaction webcam recorder (the
          &quot;React&quot; button) already provides PiP for clips that have one.
        </p>
      )}
    </>
  );
}

function BoxSizeFields({ layer, onChange, minHeight = 0.02 }: Props & { minHeight?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3">
      <div>
        <Label>Width ({Math.round((layer.width ?? 1) * 100)}%)</Label>
        <input
          type="range"
          min={0.05}
          max={1}
          step={0.01}
          value={layer.width ?? 1}
          onChange={(e) => onChange({ width: Number(e.target.value) })}
          className="mt-2.5 w-full accent-accent"
        />
      </div>
      <div>
        <Label>Height ({Math.round((layer.height ?? 0.1) * 100)}%)</Label>
        <input
          type="range"
          min={minHeight}
          max={1}
          step={0.01}
          value={layer.height ?? 0.1}
          onChange={(e) => onChange({ height: Number(e.target.value) })}
          className="mt-2.5 w-full accent-accent"
        />
      </div>
    </div>
  );
}

function TextFields({
  layer,
  props,
  onChange,
  onPropsChange,
}: {
  layer: TemplateLayer;
  props: TextLayerProps;
  onChange: (patch: Partial<TemplateLayer>) => void;
  onPropsChange: (patch: Partial<TextLayerProps>) => void;
}) {
  // "Auto-size" here means: give the text a box (width + height) instead of a
  // fixed pixel size — the backend then scales the font to fit that box (and
  // wraps to its width) rather than using font_size verbatim. Meant for text
  // sitting inside a 'rect' color bar, where a fixed size would overflow a
  // short bar or look tiny in a tall one. Toggling clears whichever of
  // font_size/height isn't relevant to the chosen mode so they don't conflict —
  // the backend's rule is simply "font_size wins when set, else auto-size from
  // height".
  const autoSize = layer.height != null;

  function toggleAutoSize(enabled: boolean) {
    if (enabled) {
      onChange({ height: layer.height ?? 0.15, width: layer.width ?? 0.9 });
      onPropsChange({ font_size: undefined });
    } else {
      onChange({ height: null, width: null });
      onPropsChange({ font_size: props.font_size ?? 48 });
    }
  }

  return (
    <>
      <div>
        <Label>Text</Label>
        <Input value={props.content ?? ""} onChange={(e) => onPropsChange({ content: e.target.value })} />
      </div>

      <label className="flex items-center gap-2 text-xs text-muted">
        <input
          type="checkbox"
          checked={autoSize}
          onChange={(e) => toggleAutoSize(e.target.checked)}
          className="size-4 rounded accent-accent"
        />
        Auto-size to fit box (wraps and scales the font to a width/height box — good for text inside a color bar)
      </label>

      {autoSize ? (
        <BoxSizeFields layer={layer} onChange={onChange} minHeight={0.03} />
      ) : (
        <div>
          <Label>Font size</Label>
          <Input
            type="number"
            min={8}
            max={300}
            value={props.font_size ?? 48}
            onChange={(e) => onPropsChange({ font_size: Number(e.target.value) })}
          />
        </div>
      )}

      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Color</Label>
          <Input type="color" className="h-10 p-1" value={props.color ?? "#FFFFFF"} onChange={(e) => onPropsChange({ color: e.target.value })} />
        </div>
        <div>
          <Label>Align</Label>
          <Select value={props.align ?? "center"} onChange={(e) => onPropsChange({ align: e.target.value as TextLayerProps["align"] })}>
            <option value="left">Left</option>
            <option value="center">Center</option>
            <option value="right">Right</option>
          </Select>
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <Label>Stroke color</Label>
          <Input
            type="color"
            className="h-10 p-1"
            value={props.stroke_color ?? "#000000"}
            onChange={(e) => onPropsChange({ stroke_color: e.target.value })}
          />
        </div>
        <div>
          <Label>Stroke width</Label>
          <Input
            type="number"
            min={0}
            max={20}
            value={props.stroke_width ?? 0}
            onChange={(e) => onPropsChange({ stroke_width: Number(e.target.value) })}
          />
        </div>
      </div>
    </>
  );
}

function ImageFields({
  layer,
  props,
  onChange,
  onPropsChange,
}: {
  layer: TemplateLayer;
  props: ImageLayerProps;
  onChange: (patch: Partial<TemplateLayer>) => void;
  onPropsChange: (patch: Partial<ImageLayerProps>) => void;
}) {
  return (
    <>
      <div>
        <Label>Image path (on the media disk)</Label>
        <Input
          value={props.image_path ?? ""}
          placeholder="branding/logo.png"
          onChange={(e) => onPropsChange({ image_path: e.target.value })}
        />
      </div>
      <div>
        <Label>Width ({layer.width == null ? "auto" : `${Math.round((layer.width ?? 0) * 100)}%`})</Label>
        <div className="flex items-center gap-2">
          <input
            type="range"
            min={0.02}
            max={1}
            step={0.01}
            value={layer.width ?? 0.2}
            onChange={(e) => onChange({ width: Number(e.target.value) })}
            className="w-full accent-accent"
          />
        </div>
      </div>
    </>
  );
}

function AudioFields({ props, onChange }: { props: AudioLayerProps; onChange: (patch: Partial<AudioLayerProps>) => void }) {
  return (
    <>
      <div>
        <Label>Audio path (on the media disk)</Label>
        <Input
          value={props.audio_path ?? ""}
          placeholder="assets/music/track.mp3"
          onChange={(e) => onChange({ audio_path: e.target.value })}
        />
      </div>
      <div className="grid grid-cols-3 gap-3">
        <div>
          <Label>Volume ({Math.round((props.volume ?? 0.3) * 100)}%)</Label>
          <input
            type="range"
            min={0}
            max={1}
            step={0.05}
            value={props.volume ?? 0.3}
            onChange={(e) => onChange({ volume: Number(e.target.value) })}
            className="mt-2.5 w-full accent-accent"
          />
        </div>
        <div>
          <Label>Fade in (s)</Label>
          <Input type="number" min={0} step={0.5} value={props.fade_in ?? 0} onChange={(e) => onChange({ fade_in: Number(e.target.value) })} />
        </div>
        <div>
          <Label>Fade out (s)</Label>
          <Input type="number" min={0} step={0.5} value={props.fade_out ?? 0} onChange={(e) => onChange({ fade_out: Number(e.target.value) })} />
        </div>
      </div>
    </>
  );
}

function ProgressBarFields({
  props,
  onChange,
}: {
  props: ProgressBarLayerProps;
  onChange: (patch: Partial<ProgressBarLayerProps>) => void;
}) {
  return (
    <div className="grid grid-cols-3 gap-3">
      <div>
        <Label>Color</Label>
        <Input type="color" className="h-10 p-1" value={props.color ?? "#7C5CFF"} onChange={(e) => onChange({ color: e.target.value })} />
      </div>
      <div>
        <Label>Height (px)</Label>
        <Input
          type="number"
          min={1}
          max={40}
          value={props.height_px ?? 6}
          onChange={(e) => onChange({ height_px: Number(e.target.value) })}
        />
      </div>
      <div>
        <Label>Position</Label>
        <Select value={props.position ?? "bottom"} onChange={(e) => onChange({ position: e.target.value as ProgressBarLayerProps["position"] })}>
          <option value="top">Top</option>
          <option value="bottom">Bottom</option>
        </Select>
      </div>
    </div>
  );
}

function RectFields({
  layer,
  props,
  onChange,
  onPropsChange,
}: {
  layer: TemplateLayer;
  props: RectLayerProps;
  onChange: (patch: Partial<TemplateLayer>) => void;
  onPropsChange: (patch: Partial<RectLayerProps>) => void;
}) {
  return (
    <>
      <div>
        <Label>Color</Label>
        <Input type="color" className="h-10 p-1" value={props.color ?? "#000000"} onChange={(e) => onPropsChange({ color: e.target.value })} />
      </div>
      <p className="text-[11px] text-muted">
        A solid rectangle — pair it with a &quot;text&quot; layer positioned inside it (auto-size on) to build a
        headline or branding bar. X/Y here are the box&apos;s top-left corner, not centered like text/image layers.
      </p>
      <BoxSizeFields layer={layer} onChange={onChange} />
    </>
  );
}
