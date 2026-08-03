import { useLocalStorage } from '@uidotdev/usehooks';
import { useEffect, useMemo } from 'react';

const ThemeProps = {
  key: 'theme',
  light: 'light',
  dark: 'dark',
} as const;

type Theme = typeof ThemeProps.light | typeof ThemeProps.dark;

export const useTheme = (defaultTheme?: Theme) => {
  const [theme, setTheme] = useLocalStorage<Theme>(ThemeProps.key, defaultTheme);

  const isDark = useMemo(() => theme === ThemeProps.dark, [theme]);
  const isLight = useMemo(() => theme === ThemeProps.light, [theme]);

  const applyThemeClass = (nextTheme: Theme) => {
    document.documentElement.classList.remove(ThemeProps.light, ThemeProps.dark);
    document.documentElement.classList.add(nextTheme);
  };

  const setThemeClass = (nextTheme: Theme) => {
    setTheme(nextTheme);
    applyThemeClass(nextTheme);
  };

  const setLightTheme = () => setThemeClass(ThemeProps.light);
  const setDarkTheme = () => setThemeClass(ThemeProps.dark);
  const toggleTheme = () => theme === ThemeProps.dark ? setLightTheme() : setDarkTheme();

  useEffect(() => {
    applyThemeClass(theme);
  }, [theme]);

  return { theme, isDark, isLight, setLightTheme, setDarkTheme, toggleTheme };
};
