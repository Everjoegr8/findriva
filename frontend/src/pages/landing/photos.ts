/**
 * Photos from Unsplash, free to use under the Unsplash License (https://unsplash.com/license).
 * Served from Unsplash's image CDN, as Unsplash asks, and credited in the page footer.
 */
export interface Photo { id: string; alt: string; by: string; page: string }

export const photos = {
  storefront: { id: 'photo-1751276651723-3b9b000ce37d', alt: 'A customer looking into a shop front in Lagos', by: 'Michael Umoh', page: 'https://unsplash.com/photos/o1reZpaQ7NM' },
  boutique: { id: 'photo-1753161618091-b4cf35b9aa99', alt: 'A smiling boutique owner holding a tablet in her clothing store', by: 'Vitaly Gariev', page: 'https://unsplash.com/photos/PqZNF90tfyU' },
  streetFood: { id: 'photo-1722072391426-964abfef1924', alt: 'A woman grilling plantain (bole) at a busy street food stall in Ibadan', by: 'Tunde Buremo', page: 'https://unsplash.com/photos/Tb9D4Ix9oYo' },
  tomatoes: { id: 'photo-1575303093127-18b3c4ef8c41', alt: 'A market trader holding fresh tomatoes at her stall', by: 'Omotayo Tajudeen', page: 'https://unsplash.com/s/photos/nigerian-business' },
  shop: { id: 'photo-1761370571806-886404629697', alt: 'A shop owner standing in his small shop', by: 'Muhammad-Taha Ibrahim', page: 'https://unsplash.com/s/photos/nigerian-business' },
  market: { id: 'photo-1785094165709-54fef69c2c4d', alt: 'Aerial view of a busy Lagos street market with colourful umbrellas', by: 'Fahd Aminu', page: 'https://unsplash.com/s/photos/lagos-market' },
  city: { id: 'photo-1785094165599-7fe887d264d4', alt: 'Lagos skyline with busy streets and the lagoon', by: 'Fahd Aminu', page: 'https://unsplash.com/s/photos/lagos-market' },
} satisfies Record<string, Photo>

export function src(p: Photo, w: number, h?: number) {
  return `https://images.unsplash.com/${p.id}?auto=format&fit=crop&w=${w}${h ? `&h=${h}` : ''}&q=70`
}

export function srcSet(p: Photo, w: number, h?: number) {
  const r = h ? h / w : undefined
  return [1, 2].map((x) => `${src(p, w * x, r ? Math.round(w * x * r) : undefined)} ${x}x`).join(', ')
}
